<?php
declare(strict_types=1);

/**
 * Feed Settings Panel
 *
 * Opens a feed's settings in a floating panel over the page you are already on,
 * instead of navigating to the subscription page. Right-click a feed in the
 * sidebar or in the article list.
 *
 * Nothing is written to FreshRSS's data when this extension is enabled. The
 * panel writes only the fields the user enables on the extension page, and only
 * when they press Save. With the extension disabled its script is never
 * requested and the DOM is stock.
 */
class RightClickActionsFeedSettingsPanelExtension extends Minz_Extension
{
	/**
	 * Every field the panel can show.
	 *
	 *   group  — section heading, on the extension page and in the panel
	 *   type   — text | textarea | url | password | number | select |
	 *            select-bool | checkbox | info
	 *   writes — false means the panel shows it read-only
	 *   source — 'categories' fills the options at runtime
	 */
	private const FIELDS = [
		'name' => [
			'label' => 'Title', 'group' => 'Feed', 'type' => 'text', 'writes' => true,
			'hint' => 'The name shown in the sidebar and the article list.',
		],
		'url' => [
			'label' => 'Feed URL', 'group' => 'Feed', 'type' => 'url', 'writes' => true,
			'hint' => 'Changing it does not re-fetch the feed until the next refresh.',
		],
		'website' => [
			'label' => 'Website URL', 'group' => 'Feed', 'type' => 'url', 'writes' => true,
			'hint' => 'The site the feed belongs to.',
		],
		'description' => [
			'label' => 'Description', 'group' => 'Feed', 'type' => 'textarea', 'writes' => true,
			'hint' => 'Free text, shown only here.',
		],
		'category' => [
			'label' => 'Category', 'group' => 'Feed', 'type' => 'select', 'writes' => true,
			'source' => 'categories',
			'hint' => 'Moves the feed into another category.',
		],
		'priority' => [
			'label' => 'Priority', 'group' => 'Feed', 'type' => 'select', 'writes' => true,
			'options' => [
				['value' => 20, 'label' => 'Important'],
				['value' => 10, 'label' => 'Main stream'],
				['value' => 0, 'label' => 'In its category'],
				['value' => -5, 'label' => 'Feed only'],
				['value' => -10, 'label' => 'Do not show'],
			],
			'hint' => 'Where the feed appears. "Do not show" hides it everywhere.',
		],

		'ttl' => [
			'label' => 'Refresh interval', 'group' => 'Refresh', 'type' => 'select', 'writes' => true,
			'options' => [
				['value' => 0, 'label' => 'Use default'],
				['value' => 300, 'label' => '5 minutes'],
				['value' => 900, 'label' => '15 minutes'],
				['value' => 1800, 'label' => '30 minutes'],
				['value' => 3600, 'label' => '1 hour'],
				['value' => 21600, 'label' => '6 hours'],
				['value' => 43200, 'label' => '12 hours'],
				['value' => 86400, 'label' => '1 day'],
			],
			'hint' => 'How often this feed is checked for new articles.',
		],
		'mute' => [
			'label' => 'Mute', 'group' => 'Refresh', 'type' => 'checkbox', 'writes' => true,
			'hint' => 'Stops the feed from being refreshed at all.',
		],

		'show_unread_count' => [
			'label' => 'Show unread count', 'group' => 'Display', 'type' => 'select-bool', 'writes' => true,
			'hint' => 'Whether this feed\'s number appears in the sidebar.',
		],
		'display_enclosures' => [
			'label' => 'Show enclosure links', 'group' => 'Display', 'type' => 'select-bool', 'writes' => true,
			'hint' => 'Whether audio and video attachments are listed in the stream.',
		],
		'defaultSortOrder' => [
			'label' => 'Sort order', 'group' => 'Display', 'type' => 'select', 'writes' => true,
			'options' => [
				['value' => '', 'label' => 'Use default'],
				['value' => 'date_desc', 'label' => 'Newest first'],
				['value' => 'date_asc', 'label' => 'Oldest first'],
				['value' => 'id_desc', 'label' => 'Newest added first'],
				['value' => 'title_asc', 'label' => 'Title A to Z'],
				['value' => 'title_desc', 'label' => 'Title Z to A'],
			],
			'hint' => 'Default article order when only this feed is shown.',
		],

		'mark_updated_article_unread' => [
			'label' => 'Updated articles become unread', 'group' => 'Behaviour', 'type' => 'select-bool', 'writes' => true,
			'hint' => 'Re-marks an article unread when its content changes upstream.',
		],
		'archive_keep_max' => [
			'label' => 'Keep at most N articles', 'group' => 'Archiving', 'type' => 'number', 'writes' => true,
			'placeholder' => 'Empty = keep all',
			'hint' => 'Older articles beyond this count are deleted on refresh.',
		],
		'archive_keep_period' => [
			'label' => 'Keep articles for', 'group' => 'Archiving', 'type' => 'select', 'writes' => true,
			'options' => [
				['value' => '', 'label' => 'Keep all'],
				['value' => 'P1W', 'label' => '1 week'],
				['value' => 'P1M', 'label' => '1 month'],
				['value' => 'P3M', 'label' => '3 months'],
				['value' => 'P6M', 'label' => '6 months'],
				['value' => 'P1Y', 'label' => '1 year'],
			],
			'hint' => 'Articles older than this are deleted on refresh.',
		],

		'http_user' => [
			'label' => 'HTTP username', 'group' => 'Access', 'type' => 'text', 'writes' => true,
			'hint' => 'Only for feeds behind HTTP basic auth.',
		],
		'http_pass' => [
			'label' => 'HTTP password', 'group' => 'Access', 'type' => 'password', 'writes' => true,
			'hint' => 'Leave blank to keep the stored password.',
		],
		'unread_count_readonly' => [
			'label' => 'Unread / total count', 'group' => 'Info', 'type' => 'info', 'writes' => false,
			'hint' => 'A read-only line showing this feed\'s article counts.',
		],

		'action_refresh_feed' => [
			'label' => 'Update feed now', 'group' => 'Actions', 'type' => 'button', 'writes' => true,
			'action' => 'refresh',
			'hint' => 'Fetches this feed immediately instead of waiting for the next scheduled refresh.',
		],
		'action_reload_articles' => [
			'label' => 'Reload articles', 'group' => 'Actions', 'type' => 'button', 'writes' => true,
			'action' => 'reload',
			'hint' => 'Deletes this feed\'s stored articles and fetches them again from the source. Ask first: what the source still offers is what comes back.',
		],
		'action_delete_articles' => [
			'label' => 'Delete all articles', 'group' => 'Actions', 'type' => 'button', 'writes' => true,
			'danger' => true, 'action' => 'delete',
			'hint' => 'Deletes this feed\'s stored articles and keeps the feed subscribed.',
		],
		'action_export' => [
			'label' => 'Export articles (OPML)', 'group' => 'Actions', 'type' => 'button', 'writes' => false,
			'link' => true, 'action' => 'export',
			'hint' => 'Downloads this feed\'s articles as an OPML file. Nothing is changed.',
		],
		'action_remove_feed' => [
			'label' => 'Remove feed', 'group' => 'Actions', 'type' => 'button', 'writes' => true,
			'danger' => true, 'action' => 'remove',
			'hint' => 'Unsubscribes the feed and deletes its articles. Ask first: this cannot be undone.',
		],
	];

	/** Groups, in the order they appear. */
	private const GROUPS = ['Feed', 'Refresh', 'Display', 'Behaviour', 'Archiving', 'Access', 'Info', 'Actions'];

	/** Fields enabled before the user touches the extension page. */
	private const DEFAULT_ENABLED = ['name', 'url', 'description', 'website'];

	private const STATE_DIR = 'extensions/FeedSettingsPanel';

	public function init(): void
	{
		// Register the controller so the panel's data endpoint resolves.
		$this->registerController('fsp');
		$this->registerTranslates();

		// Panel assets load on every page: the panel is opened from the sidebar
		// and the article list, so there is no narrower page to scope it to.
		Minz_View::appendStyle($this->getFileUrl('panel.css'));
		Minz_View::appendScript($this->getFileUrl('panel.js'), cond: false, defer: true, async: false);

		$this->registerHook(Minz_HookType::JsVars, [$this, 'injectJsVars']);
	}

	/**
	 * @param array<string,mixed> $vars
	 * @return array<string,mixed>
	 */
	public function injectJsVars(array $vars): array
	{
		$vars[$this->getName()] = [
			'enabled' => $this->enabledFields(),
			'csrf' => FreshRSS_Auth::csrfToken(),
			// _url() is HTML-escaped; decode so fetch() sends a real '&'.
			'panelUrl' => html_entity_decode((string)_url('fsp', 'panel'), ENT_QUOTES),
			'saveUrl' => html_entity_decode((string)_url('fsp', 'save'), ENT_QUOTES),
			'actionUrl' => html_entity_decode((string)_url('fsp', 'action'), ENT_QUOTES),
			'exportUrl' => html_entity_decode((string)_url('fsp', 'export'), ENT_QUOTES),
		];
		return $vars;
	}

	/** @return list<string> */
	public function enabledFields(): array
	{
		$state = $this->readState();
		$out = [];
		foreach (array_keys(self::FIELDS) as $key) {
			if ($state[$key]) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/** @return array<string,array<string,mixed>> */
	public static function fieldDefs(): array
	{
		return self::FIELDS;
	}

	/** @return list<string> */
	public static function groups(): array
	{
		return self::GROUPS;
	}

	/** @return list<string> */
	public static function defaults(): array
	{
		return self::DEFAULT_ENABLED;
	}

	/**
	 * State lives in a JSON file, not config.php: a feed refresh holds the whole
	 * user-config object and would silently drop a key written mid-refresh.
	 * @return array<string,bool>
	 */
	public function readState(): array
	{
		$defaults = array_fill_keys(array_keys(self::FIELDS), false);
		foreach (self::DEFAULT_ENABLED as $k) {
			$defaults[$k] = true;
		}

		$file = $this->stateFile();
		if ($file === null || !is_file($file)) {
			return $defaults;
		}
		$raw = @file_get_contents($file);
		if ($raw === false || $raw === '') {
			return $defaults;
		}
		$data = json_decode($raw, true);
		if (!is_array($data) || !isset($data['fields']) || !is_array($data['fields'])) {
			return $defaults;
		}
		$out = [];
		foreach (array_keys(self::FIELDS) as $k) {
			$out[$k] = !empty($data['fields'][$k]);
		}
		return $out;
	}

	/** @param array<string,bool> $fields */
	public function writeState(array $fields): bool
	{
		$file = $this->stateFile(create: true);
		if ($file === null) {
			return false;
		}
		$payload = ['fields' => []];
		foreach (array_keys(self::FIELDS) as $k) {
			$payload['fields'][$k] = !empty($fields[$k]);
		}
		$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			return false;
		}
		$tmp = $file . '.tmp' . getmypid();
		if (@file_put_contents($tmp, $json) === false) {
			return false;
		}
		if (!@rename($tmp, $file)) {
			@unlink($tmp);
			return false;
		}
		return (string)@file_get_contents($file) === $json;
	}

	private function stateDir(bool $create = false): ?string
	{
		if (!defined('DATA_PATH')) {
			return null;
		}
		$user = $this->resolveUser();
		if ($user === '') {
			return null;
		}
		$dir = DATA_PATH . '/users/' . $user . '/' . self::STATE_DIR;
		if (!is_dir($dir)) {
			if (!$create) {
				return null;
			}
			if (!@mkdir($dir, 0770, true) && !is_dir($dir)) {
				return null;
			}
			@chmod($dir, 0770);
		}
		return $dir;
	}

	private function stateFile(bool $create = false): ?string
	{
		$dir = $this->stateDir($create);
		return $dir === null ? null : $dir . '/state.json';
	}

	/**
	 * A resolver, never a constant: an empty name cached once would poison every
	 * later call for the life of the Apache worker.
	 */
	public function resolveUser(): string
	{
		$candidates = [
			FreshRSS_Context::$user_conf->user ?? '',
			FreshRSS_Context::$user ?? '',
			Minz_Session::paramString('currentUser'),
		];
		foreach ($candidates as $c) {
			$c = trim((string)$c);
			if ($c !== '' && defined('DATA_PATH') && is_dir(DATA_PATH . '/users/' . $c)) {
				return $c;
			}
		}
		if (defined('DATA_PATH')) {
			foreach (@scandir(DATA_PATH . '/users') ?: [] as $n) {
				if ($n === '' || $n[0] === '.' || $n === '_' || $n === 'index.html' || $n === 'extensions') {
					continue;
				}
				if (is_dir(DATA_PATH . '/users/' . $n)) {
					return $n;
				}
			}
		}
		return '';
	}

	public function handleConfigureAction(): void
	{
		Minz_View::appendScript($this->getFileUrl('config.js'), cond: false, defer: true, async: false);
		Minz_View::appendStyle($this->getFileUrl('config-page.css'));

		if (!Minz_Request::isPost()) {
			return;
		}
		$act = Minz_Request::paramStringNull('act');
		if ($act !== 'save_fields') {
			Minz_Request::bad('Nothing was changed: the form did not say what to save.', $this->configureUrl());
			return;
		}

		$fields = [];
		foreach (array_keys(self::FIELDS) as $k) {
			$fields[$k] = Minz_Request::paramBoolean('f_' . $k);
		}

		if (!$this->writeState($fields)) {
			Minz_Request::bad('Could not save: the extension state directory is not writable.', $this->configureUrl());
			return;
		}

		$names = [];
		foreach (array_keys(array_filter($fields)) as $k) {
			$names[] = self::FIELDS[$k]['label'];
		}
		$msg = empty($names)
			? 'Saved. The panel now shows no fields — nothing can be edited from it.'
			: 'Saved. The panel shows ' . count($names) . ' fields.';
		Minz_Request::good($msg, $this->configureUrl());
	}

	private function configureUrl(): array
	{
		return ['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName()]];
	}
}
