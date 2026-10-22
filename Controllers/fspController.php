<?php
declare(strict_types=1);

/**
 * Data endpoint for the Feed Settings Panel.
 *
 *   GET  ?c=fsp&a=panel&id=<feed id>  -> JSON for the panel's enabled fields
 *   POST ?c=fsp&a=save               -> writes only the fields the user enabled
 *   POST ?c=fsp&a=action             -> remove / refresh / delete / reload
 *   GET  ?c=fsp&a=export&id=<feed id>-> the feed's articles as OPML
 *
 * The save deliberately does NOT go through core's subscription/feed POST: that
 * handler treats a missing `url` as an error and redirects the whole page.
 * updateFeed() writes only the keys it is given, so a partial write is safe.
 *
 * The enabled list is read SERVER-side on both paths. A posted field list would
 * let a request write a field the user switched off.
 */
final class FreshExtension_fsp_Controller extends Minz_ActionController
{
	private const EXT_NAME = 'Right Click Actions - Feed Settings Panel';

	/** Every action the panel can run, in menu order. These are FIELD KEYS: they
	 *  are what the state file stores and what the panel draws buttons from. */
	private const ACTIONS = ['action_refresh_feed', 'action_reload_articles', 'action_delete_articles', 'action_export', 'action_remove_feed'];

	/**
	 * The SHORT op a request carries => the field key above.
	 *
	 * The panel posts `op=remove`; the state file and the button list speak
	 * `action_remove_feed`. Validating the request against ACTIONS compared a
	 * short op with a field key, so every action was refused as "Unknown
	 * action" — and the same mix-up in the switched-off check would have
	 * refused them all a second way once the first was fixed.
	 */
	private const OPS = [
		'refresh' => 'action_refresh_feed',
		'reload' => 'action_reload_articles',
		'delete' => 'action_delete_articles',
		'remove' => 'action_remove_feed',
	];

	/** Attribute-backed fields: key => attribute name (same names core uses). */
	private const BOOL_ATTRS = [
		'show_unread_count' => 'show_unread_count',
		'display_enclosures' => 'display_enclosures',
		'mark_updated_article_unread' => 'mark_updated_article_unread',
	];

	public function firstAction(): void
	{
		if (!FreshRSS_Auth::hasAccess()) {
			$this->sendJson(['error' => 'Unauthorized'], 403);
		}
	}

	public function panelAction(): void
	{
		$id = Minz_Request::paramInt('id');
		if ($id <= 0) {
			$this->sendJson(['error' => 'Missing feed id'], 400);
		}
		$feed = FreshRSS_Factory::createFeedDao()->searchById($id);
		if ($feed === null) {
			$this->sendJson(['error' => 'Feed not found'], 404);
		}

		$enabled = $this->enabledFields();

		$categories = [];
		foreach (FreshRSS_Factory::createCategoryDao()->listCategories(true) as $cat) {
			$categories[] = ['id' => $cat->id(), 'name' => $cat->name()];
		}

		$this->sendJson([
			'ok' => true,
			'id' => $feed->id(),
			'fields' => $enabled,
			'actions' => $this->enabledActions(),
			'values' => $this->readValues($feed),
			'categories' => $categories,
			'unread' => $feed->nbNotRead(),
			'total' => $feed->nbEntries(),
		]);
	}

	/**
	 * The four buttons the panel can offer. Which ones exist is decided here,
	 * from the same state file the field toggles use, so a request can never
	 * run an action the user left switched off.
	 */
	public function actionAction(): void
	{
		if (!Minz_Request::isPost()) {
			$this->sendJson(['error' => 'POST required'], 405);
		}
		if (!FreshRSS_Auth::isCsrfOk()) {
			$this->sendJson(['error' => 'Invalid CSRF token'], 403);
		}

		$op = Minz_Request::paramString('op');
		if (!isset(self::OPS[$op])) {
			$this->sendJson(['error' => 'Unknown action'], 400);
		}
		// The panel only draws buttons for switched-on actions, so seeing the
		// button is the authorisation. A stale page still gets refused here.
		if (!in_array(self::OPS[$op], $this->enabledActions(), true)) {
			$this->sendJson([
				'error' => 'That action was switched off on the extension page. Reload this page and try again.',
			], 403);
		}

		$id = Minz_Request::paramInt('id');
		if ($id <= 0) {
			$this->sendJson(['error' => 'Missing feed id'], 400);
		}
		$feedDAO = FreshRSS_Factory::createFeedDao();
		$feed = $feedDAO->searchById($id);
		if ($feed === null) {
			$this->sendJson(['error' => 'Feed not found'], 404);
		}
		$name = $feed->name();

		switch ($op) {
			case 'refresh':
				// Core's own path: reset lastUpdate so the fetch is not skipped,
				// then let the controller do the actualizing and commit.
				$feedDAO->updateFeed($id, ['lastUpdate' => 0]);
				FreshRSS_feed_Controller::actualizeFeedsAndCommit($id);
				$feedDAO->updateCachedValues($id);
				$after = $feedDAO->searchById($id);
				$this->sendJson([
					'ok' => true,
					'message' => 'Updated "' . $name . '" \u{2014} ' . (int)($after?->nbEntries() ?? 0) . ' articles.',
					'values' => $after === null ? null : $this->readValues($after),
					'unread' => (int)($after?->nbNotRead() ?? 0),
					'total' => (int)($after?->nbEntries() ?? 0),
				]);

			case 'delete':
				// Core's own truncate(): empties the entries and zeroes the
				// feed's cached counts in one transaction.
				$n = $feedDAO->truncate($id);
				if ($n === false) {
					$this->sendJson(['error' => 'Could not delete those articles.'], 500);
				}
				$after = $feedDAO->searchById($id);
				$this->sendJson([
					'ok' => true,
					'message' => 'Deleted ' . (int)$n . ' articles from "' . $name . '". The feed is kept.',
					'values' => $after === null ? null : $this->readValues($after),
					'unread' => 0,
					'total' => 0,
				]);

			case 'reload':
				// Same order core uses: empty the feed, force lastUpdate to 0
				// so the fetch is not skipped, then actualize.
				$feedDAO->truncate($id);
				$feedDAO->updateFeed($id, ['lastUpdate' => 0]);
				FreshRSS_feed_Controller::actualizeFeedsAndCommit($id);
				$feedDAO->updateCachedValues($id);
				$after = $feedDAO->searchById($id);
				$total = (int)($after?->nbEntries() ?? 0);
				$this->sendJson([
					'ok' => true,
					'message' => $total > 0
						? 'Reloaded "' . $name . '" \u{2014} ' . $total . ' articles fetched.'
						: '"' . $name . '" was emptied but the source returned no articles. It is still subscribed.',
					'values' => $after === null ? null : $this->readValues($after),
					'unread' => (int)($after?->nbNotRead() ?? 0),
					'total' => $total,
				]);

			case 'remove':
				// deleteFeed() drops the feed row only; the entries must go too.
				$feedDAO->truncate($id);
				if ($feedDAO->deleteFeed($id) === false) {
					$this->sendJson(['error' => 'Could not remove the feed.'], 500);
				}
				$this->sendJson(['ok' => true, 'removed' => true, 'message' => 'Removed "' . $name . '".']);
		}

		$this->sendJson(['error' => 'Unknown action'], 400);
	}

	/** The feed's articles as OPML, so they can be kept or imported elsewhere. */
	public function exportAction(): void
	{
		$id = Minz_Request::paramInt('id');
		$feed = $id > 0 ? FreshRSS_Factory::createFeedDao()->searchById($id) : null;
		if ($feed === null) {
			$this->sendJson(['error' => 'Feed not found'], 404);
		}
		if (!in_array('action_export', $this->enabledActions(), true)) {
			$this->sendJson(['error' => 'Export is not switched on.'], 403);
		}

		$entries = FreshRSS_Factory::createEntryDao()->listWhere(
			type: 'f', id: $id, sort: 'date', order: 'DESC', limit: -1
		);
		$esc = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<opml version="2.0"><head><title>' . $esc($feed->name()) . '</title></head><body>'
			. '<outline text="' . $esc($feed->name()) . '" type="rss" xmlUrl="' . $esc($feed->url())
			. '" htmlUrl="' . $esc($feed->website()) . '" category="' . $esc($feed->category()?->name()) . '">';
		$n = 0;
		foreach ($entries as $e) {
			$xml .= '<outline type="link" text="' . $esc($e->title()) . '"'
				. ' url="' . $esc($e->link()) . '"'
				. ' date="' . $esc(date('r', (int)$e->date(true))) . '" />';
			$n++;
		}
		$xml .= '</outline></body></opml>';
		header('X-Feed-Panel-Articles: ' . $n);

		$slug = preg_replace('/[^a-z0-9]+/i', '-', $feed->name()) ?: 'feed';
		header('Content-Type: application/xml; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . trim((string)$slug, '-') . '-articles.opml"');
		header('Cache-Control: no-store');
		echo $xml;
		exit();
	}

	public function saveAction(): void
	{
		if (!Minz_Request::isPost()) {
			$this->sendJson(['error' => 'POST required'], 405);
		}
		if (!FreshRSS_Auth::isCsrfOk()) {
			$this->sendJson(['error' => 'Invalid CSRF token'], 403);
		}

		$id = Minz_Request::paramInt('id');
		if ($id <= 0) {
			$this->sendJson(['error' => 'Missing feed id'], 400);
		}
		$feedDAO = FreshRSS_Factory::createFeedDao();
		$feed = $feedDAO->searchById($id);
		if ($feed === null) {
			$this->sendJson(['error' => 'Feed not found'], 404);
		}

		$enabled = $this->enabledFields();
		$values = [];
		$attrs = [];
		$changed = [];
		$warnings = [];

		foreach ($enabled as $field) {
			switch ($field) {
				case 'name':
					$name = Minz_Request::paramString('name', plaintext: true);
					if (trim($name) === '') {
						$warnings[] = 'Title cannot be empty; title not changed.';
						break;
					}
					if ($name !== $feed->name(raw: true)) {
						$values['name'] = $name;
						$changed[] = 'title';
					}
					break;

				case 'url':
					$url = trim(Minz_Request::paramString('url', plaintext: true));
					if ($url === '') {
						$warnings[] = 'Feed URL cannot be empty; URL not changed.';
						break;
					}
					if ($url !== $feed->url()) {
						$values['url'] = $url;
						$changed[] = 'URL';
					}
					break;

				case 'description':
					$desc = Minz_Request::paramString('description', plaintext: true);
					if ($desc !== $feed->description()) {
						$values['description'] = $desc;
						$changed[] = 'description';
					}
					break;

				case 'website':
					$site = trim(Minz_Request::paramString('website', plaintext: true));
					if ($site !== $feed->website()) {
						$values['website'] = $site;
						$changed[] = 'website';
					}
					break;

				case 'category':
					$cat = Minz_Request::paramInt('category');
					if ($cat > 0 && $cat !== $feed->categoryId()) {
						if (FreshRSS_Factory::createCategoryDao()->searchById($cat) === null) {
							$warnings[] = 'That category no longer exists; category not changed.';
							break;
						}
						$values['category'] = $cat;
						$changed[] = 'category';
					}
					break;

				case 'priority':
					$prio = Minz_Request::paramInt('priority');
					$allowed = [
						FreshRSS_Feed::PRIORITY_IMPORTANT,
						FreshRSS_Feed::PRIORITY_MAIN_STREAM,
						FreshRSS_Feed::PRIORITY_CATEGORY,
						FreshRSS_Feed::PRIORITY_FEED,
						FreshRSS_Feed::PRIORITY_HIDDEN,
					];
					if (in_array($prio, $allowed, true) && $prio !== $feed->priority()) {
						$values['priority'] = $prio;
						$changed[] = 'priority';
					}
					break;

				case 'ttl':
					$ttl = Minz_Request::paramInt('ttl');
					if ($ttl < 0) {
						$ttl = 0;
					}
					if ($ttl !== $feed->ttl(raw: true)) {
						$values['ttl'] = $ttl;
						$changed[] = 'refresh interval';
					}
					break;

				case 'mute':
					$mute = Minz_Request::paramBoolean('mute');
					if ($mute !== $feed->mute()) {
						$values['mute'] = $mute;
						$changed[] = 'mute';
					}
					break;

				case 'show_unread_count':
				case 'display_enclosures':
				case 'mark_updated_article_unread':
					// Core uses a ternary: '' = inherit, '0' = no, '1' = yes.
					$raw = Minz_Request::paramString('' . $field);
					$target = ($raw === '1') ? true : (($raw === '0') ? false : null);
					$current = $feed->attributeBoolean(self::BOOL_ATTRS[$field]);
					if ($target !== $current) {
						$attrs[self::BOOL_ATTRS[$field]] = $target;
						$changed[] = strtolower((string)preg_replace('/[A-Z]/', ' $0', $field));
					}
					break;

				case 'defaultSortOrder':
					$sortOrder = Minz_Request::paramString('defaultSortOrder');
					[$sort, $order] = self::splitSortOrder($sortOrder);
					$curSort = $feed->defaultSort();
					$curOrder = $feed->defaultOrder();
					if ($sort !== $curSort || ($sort !== null && strtoupper((string)$order) !== strtoupper((string)$curOrder))) {
						$attrs['defaultSort'] = $sort;
						$attrs['defaultOrder'] = $order;
						$changed[] = 'sort order';
					}
					break;

				case 'archive_keep_max':
					$keepMaxRaw = Minz_Request::paramString('archive_keep_max');
					$keepMax = ($keepMaxRaw === '') ? false : max(1, (int)$keepMaxRaw);
					if ((string)$keepMax !== self::archivingValue($feed, 'keep_max')) {
						$attrs['archiving'] = self::mergeArchiving($feed, ['keep_max' => $keepMax]);
						$changed[] = 'article retention';
					}
					break;

				case 'archive_keep_period':
					$period = Minz_Request::paramString('archive_keep_period');
					if ($period !== '' && !preg_match('/^PT?\d+[YMWDH]$/', $period)) {
						$warnings[] = 'That retention period is not valid; period not changed.';
						break;
					}
					$target = $period === '' ? false : $period;
					if ((string)$target !== self::archivingValue($feed, 'keep_period')) {
						$attrs['archiving'] = self::mergeArchiving($feed, ['keep_period' => $target]);
						$changed[] = 'retention period';
					}
					break;

				case 'http_user':
				case 'http_pass':
					// Both halves travel together as one attribute; an empty
					// password means "keep what is stored".
					$user = Minz_Request::paramString('http_user', plaintext: true);
					$pass = Minz_Request::paramString('http_pass', plaintext: true);
					$current = $feed->httpAuth(raw: false);
					$curUser = is_array($current) ? (string)($current['username'] ?? '') : '';
					$curPass = is_array($current) ? (string)($current['password'] ?? '') : '';
					if ($user !== $curUser || ($pass !== '' && $pass !== $curPass)) {
						$changed[] = 'HTTP access';
						$attrs['httpAuth'] = ['username' => $user, 'password' => $pass === '' ? $curPass : $pass];
					}
					break;

				case 'unread_count_readonly':
					// Read-only by definition; nothing to write.
					break;
			}
		}

		if (empty($values) && empty($attrs)) {
			$this->sendJson([
				'ok' => true,
				'changed' => [],
				'message' => empty($warnings) ? 'Nothing changed.' : implode(' ', $warnings),
			]);
		}

		$ok = empty($values) ? true : $feedDAO->updateFeed($id, $values);
		if ($ok === false) {
			$this->sendJson(['error' => 'The database write failed; nothing was changed.'], 500);
		}
		foreach ($attrs as $key => $val) {
			$feed->_attribute($key, $val);
		}
		if (!empty($attrs) && $feedDAO->updateFeed($id, ['attributes' => $feed->attributes()]) === false) {
			$this->sendJson(['error' => 'The settings write failed.'], 500);
		}

		// Re-read and report what is actually on disk, not what was sent.
		$after = $feedDAO->searchById($id);
		$this->sendJson([
			'ok' => true,
			'changed' => $changed,
			'message' => 'Saved: ' . implode(', ', array_unique($changed)) . '.'
				. (empty($warnings) ? '' : ' ' . implode(' ', $warnings)),
			'values' => $after === null ? null : $this->readValues($after),
		]);
	}

	/** @return list<string> */
	private function enabledFields(): array
	{
		$ext = Minz_ExtensionManager::findExtension(self::EXT_NAME);
		return $ext instanceof RightClickActionsFeedSettingsPanelExtension ? $ext->enabledFields() : [];
	}

	/** @return list<string> */
	private function enabledActions(): array
	{
		$ext = Minz_ExtensionManager::findExtension(self::EXT_NAME);
		if (!$ext instanceof RightClickActionsFeedSettingsPanelExtension) {
			return [];
		}
		$state = $ext->readState();
		return array_values(array_filter(self::ACTIONS, static fn(string $k): bool => !empty($state[$k])));
	}

	/** @return array<string,mixed> */
	private function readValues(FreshRSS_Feed $feed): array
	{
		$sort = $feed->defaultSort();
		$order = $feed->defaultOrder();
		$sortOrder = $sort === null ? '' : ($sort === 'rand' ? 'rand' : $sort . '_' . strtolower($order ?? 'desc'));

		$auth = $feed->httpAuth(raw: false);

		return [
			'name' => $feed->name(raw: true),
			'url' => $feed->url(),
			'description' => $feed->description(),
			'website' => $feed->website(),
			'category' => $feed->categoryId(),
			'priority' => $feed->priority(),

			'ttl' => $feed->ttl(raw: true),
			'mute' => $feed->mute() ? '1' : '0',

			'show_unread_count' => self::boolToString($feed->attributeBoolean('show_unread_count')),
			'display_enclosures' => self::boolToString($feed->attributeBoolean('display_enclosures')),
			'mark_updated_article_unread' => self::boolToString($feed->attributeBoolean('mark_updated_article_unread')),
			'defaultSortOrder' => $sortOrder,

			'archive_keep_max' => self::archivingValue($feed, 'keep_max'),
			'archive_keep_period' => self::archivingValue($feed, 'keep_period'),

			'http_user' => is_array($auth) ? (string)($auth['username'] ?? '') : '',
			'http_pass' => is_array($auth) ? (string)($auth['password'] ?? '') : '',
			'unread_count_readonly' => $feed->nbNotRead() . ' unread / ' . $feed->nbEntries() . ' total',
		];
	}

	/**
	 * Core stores retention in ONE nested attribute (keep_period / keep_max /
	 * keep_min / keep_favourites / keep_labels / keep_unreads). Writing a flat
	 * key would be ignored, so read and merge inside that array.
	 */
	private static function archivingValue(FreshRSS_Feed $feed, string $key): string
	{
		$archiving = $feed->attributeArray('archiving');
		if (!is_array($archiving) || !array_key_exists($key, $archiving)) {
			return '';
		}
		$v = $archiving[$key];
		return ($v === false || $v === null) ? '' : (string)$v;
	}

	/** @param array<string,mixed> $patch @return array<string,mixed> */
	private static function mergeArchiving(FreshRSS_Feed $feed, array $patch): array
	{
		$archiving = $feed->attributeArray('archiving') ?? [
			'keep_period' => false,
			'keep_max' => false,
			'keep_min' => 0,
			'keep_favourites' => true,
			'keep_labels' => true,
			'keep_unreads' => false,
		];
		return array_merge($archiving, $patch);
	}

	private static function boolToString(?bool $v): string
	{
		return $v === null ? '' : ($v ? '1' : '0');
	}

	/** @return array{0:?string,1:?string} */
	private static function splitSortOrder(string $value): array
	{
		if ($value === '') {
			return [null, null];
		}
		$parts = explode('_', $value, 2);
		if (count($parts) !== 2) {
			return [null, null];
		}
		$order = strtolower($parts[1]) === 'asc' ? 'ASC' : 'DESC';
		return [$parts[0], $order];
	}

	/** @param array<string,mixed> $data */
	private function sendJson(array $data, int $status = 200): never
	{
		header('Content-Type: application/json', true, $status);
		header('Cache-Control: no-store');
		echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit();
	}
}
