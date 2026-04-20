<?php declare(strict_types = 0);

require_once dirname(__FILE__).'/../../../include/defines.inc.php';
require_once dirname(__FILE__).'/../../../include/html.inc.php';

class CVoiceAIRawHtml extends CObject {
	private $html = '';

	public function __construct(string $html) {
		parent::__construct();
		$this->html = $html;
	}

	public function toString($destroy = true) {
		return $this->html;
	}
}

$hostid = (int) ($data['hostid'] ?? 0);
$host = $data['host'] ?? null;
$error = $data['error'] ?? null;
$premium_map_enabled = (bool) ($data['premium_map_enabled'] ?? false);
$premium_endpoints = $data['premium_map_endpoints'] ?? [];
$host_name = $host ? ($host['name'] ?: $host['host']) : _('Unknown host');
$host_tags = $host['tags'] ?? [];

$back_url = (new CUrl('zabbix.php'))
	->setArgument('action', 'zabgraph.view')
	->getUrl();

$html_page = (new CHtmlPage())
	->setTitle(_('Host Observability'))
	->setDocUrl('')
	->setControls(
		(new CTag('nav', true,
			(new CList())
				->addItem(
					(new CLink(_('Back to ZabGraph'), $back_url))
						->addClass(ZBX_STYLE_BTN_ALT)
				)
		))->setAttribute('aria-label', _('Content controls'))
	);

$content = (new CDiv())->addClass('zg-hostobs-page')->setId('zg-hostobs-page');

$tags_html = '';
foreach ($host_tags as $tag) {
	$k = htmlspecialchars((string) ($tag['tag'] ?? ''), ENT_QUOTES, 'UTF-8');
	$v = htmlspecialchars((string) ($tag['value'] ?? ''), ENT_QUOTES, 'UTF-8');
	$tags_html .= '<span class="zg-hostobs-tag">'.$k.'='.$v.'</span>';
}
if ($tags_html === '') {
	$tags_html = '<span class="zg-hostobs-tag">env=unknown</span>';
}

if ($error !== null) {
	$content->addItem((new CDiv($error))->addClass('msg-bad'));
}

$content->addItem(new CVoiceAIRawHtml(
	'<div class="zg-hostobs-header">'
		.'<div class="zg-hostobs-title-wrap">'
			.'<h2 class="zg-hostobs-title">Intelligent Observability Panel</h2>'
			.'<div class="zg-hostobs-subtitle">Host: '.htmlspecialchars($host_name, ENT_QUOTES, 'UTF-8').'</div>'
			.'<div class="zg-hostobs-tags">'.$tags_html.'</div>'
		.'</div>'
		.'<div class="zg-hostobs-controls">'
			.'<label>Window <select id="zg-hostobs-window"><option value="1h">1h</option><option value="6h" selected>6h</option><option value="24h">24h</option></select></label>'
			.'<label>Severity <select id="zg-hostobs-severity"><option value="all" selected>All</option><option value="critical">Critical only</option><option value="warning">Warning+</option></select></label>'
			.'<label>Metric <select id="zg-hostobs-metric"><option value="all" selected>All</option><option value="cpu">CPU</option><option value="memory">Memory</option><option value="network">Network</option><option value="disk">Disk</option></select></label>'
			.'<button type="button" class="btn-alt" id="zg-hostobs-refresh">Refresh</button>'
		.'</div>'
	.'</div>'
));

$content->addItem(new CVoiceAIRawHtml(
	'<div class="zg-hostobs-layout'.($premium_map_enabled ? ' zg-hostobs-layout-premium' : '').'">'
		.($premium_map_enabled
			? '<aside class="zg-premium-map" id="zg-premium-map">'
				.'<div class="zg-premium-map-head">'
					.'<div class="zg-premium-map-title">Map Copilot</div>'
					.'<div class="zg-premium-map-subtitle">Turn any data into an interactive knowledge map</div>'
					.'<div class="zg-premium-map-tools">'
						.'<button type="button" class="btn-alt zg-premium-layout-btn is-active" data-layout="cose">Organic</button>'
						.'<button type="button" class="btn-alt zg-premium-layout-btn" data-layout="concentric">Concentric</button>'
						.'<button type="button" class="btn-alt zg-premium-layout-btn" data-layout="breadthfirst">Flow</button>'
						.'<button type="button" class="btn-alt" id="zg-premium-fit">Fit</button>'
					.'</div>'
				.'</div>'
				.'<div class="zg-premium-chat-log" id="zg-premium-chat-log">'
					.'<div class="zg-premium-chat-row ai">I can analyze host telemetry, alerts, tags, and uploaded JSON to build a structured web map. Ask anything.</div>'
				.'</div>'
				.'<form id="zg-premium-chat-form" class="zg-premium-chat-form">'
					.'<textarea id="zg-premium-question" placeholder="Ask anything about this host, incidents, dependencies, or uploaded data..."></textarea>'
					.'<div class="zg-premium-chat-actions">'
						.'<button type="submit" class="btn-alt">Analyze & Map</button>'
						.'<span id="zg-premium-chat-status" class="zg-premium-status">Ready</span>'
					.'</div>'
				.'</form>'
				.'<div class="zg-premium-upload">'
					.'<div class="zg-premium-upload-title">Attach JSON Data</div>'
					.'<form id="zg-premium-upload-form" class="zg-premium-upload-form" enctype="multipart/form-data">'
						.'<input type="file" id="zg-premium-upload-file" name="file" accept="application/json,.json">'
						.'<textarea id="zg-premium-upload-text" name="json_text" placeholder="...or paste JSON content here"></textarea>'
						.'<button type="submit" class="btn-alt">Parse & Add to Map</button>'
					.'</form>'
				.'</div>'
				.'<div class="zg-premium-map-canvas-wrap">'
					.'<div class="zg-premium-map-canvas" id="zg-premium-map-canvas"></div>'
				.'</div>'
			'</aside>'
			: '')
		.'<section class="zg-hostobs-graphs">'
			.'<div class="zg-hostobs-badges" id="zg-hostobs-badges"></div>'
			.'<div class="zg-hostobs-card"><div class="zg-hostobs-card-title">CPU + Memory Correlation</div><div id="zg-chart-cpu-memory" class="zg-hostobs-chart"></div></div>'
			.'<div class="zg-hostobs-card"><div class="zg-hostobs-card-title">Network In/Out</div><div id="zg-chart-network" class="zg-hostobs-chart"></div></div>'
			.'<div class="zg-hostobs-card"><div class="zg-hostobs-card-title">Disk + Incident Timeline</div><div id="zg-chart-disk" class="zg-hostobs-chart zg-hostobs-chart-small"></div></div>'
		.'</section>'
		.'<aside class="zg-hostobs-panel">'
			.'<div class="zg-hostobs-panel-card"><div class="zg-hostobs-panel-title">Current Status</div><div id="zg-hostobs-status" class="zg-hostobs-status">-</div><div id="zg-hostobs-summary" class="zg-hostobs-summary"></div></div>'
			.'<div class="zg-hostobs-panel-card"><div class="zg-hostobs-panel-title">Active Issues (Top 3)</div><div id="zg-hostobs-issues"></div></div>'
			.'<div class="zg-hostobs-panel-card"><div class="zg-hostobs-panel-title">Detected Alerts (Auto)</div><div id="zg-hostobs-detected-alerts"></div></div>'
			.'<div class="zg-hostobs-panel-card"><div class="zg-hostobs-panel-title">AI Explanation</div><table class="zg-hostobs-table"><tbody><tr><th>Summary</th><td id="zg-ai-summary">-</td></tr><tr><th>Root Cause</th><td id="zg-ai-root">-</td></tr><tr><th>Impact</th><td id="zg-ai-impact">-</td></tr><tr><th>Suggested Action</th><td id="zg-ai-fix">-</td></tr></tbody></table></div>'
			.'<div class="zg-hostobs-panel-card"><div class="zg-hostobs-panel-title">Incident Timeline</div><div id="zg-hostobs-timeline" class="zg-hostobs-timeline"></div></div>'
		.'</aside>'
	.'</div>'
));

$content->addItem((new CTag('script', true))->setAttribute('src', 'https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js'));
if ($premium_map_enabled) {
	$content->addItem((new CTag('script', true))->setAttribute('src', 'https://cdn.jsdelivr.net/npm/cytoscape@3.29.2/dist/cytoscape.min.js'));
}

ob_start();
$voiceai_data_url = (new CUrl('zabbix.php'))->setArgument('action', 'voiceai.hostgraphs.data')->getUrl();
$voiceai_hostid = $hostid;
$voiceai_premium_enabled = $premium_map_enabled;
$voiceai_map_data_url = (string) ($premium_endpoints['graph_data'] ?? '');
$voiceai_map_chat_url = (string) ($premium_endpoints['chat'] ?? '');
$voiceai_map_upload_url = (string) ($premium_endpoints['upload'] ?? '');
$voiceai_map_layout_save_url = (string) ($premium_endpoints['layout_save'] ?? '');
include dirname(__FILE__).'/js/voiceai.hostgraphs.view.js.php';
$content->addItem(new CScriptTag(ob_get_clean()));

$html_page->addItem($content);
$html_page->show();
