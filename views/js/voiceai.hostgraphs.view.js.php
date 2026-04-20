(function() {
	if (typeof echarts === 'undefined') {
		return;
	}

	var hostid = <?php echo json_encode((string) $voiceai_hostid); ?>;
	var dataUrl = <?php echo json_encode($voiceai_data_url); ?>;
	var premiumEnabled = <?php echo json_encode((bool) ($voiceai_premium_enabled ?? false)); ?>;
	var mapDataUrl = <?php echo json_encode((string) ($voiceai_map_data_url ?? '')); ?>;
	var mapChatUrl = <?php echo json_encode((string) ($voiceai_map_chat_url ?? '')); ?>;
	var mapUploadUrl = <?php echo json_encode((string) ($voiceai_map_upload_url ?? '')); ?>;
	var mapLayoutSaveUrl = <?php echo json_encode((string) ($voiceai_map_layout_save_url ?? '')); ?>;
	var cpuMemChart = echarts.init(document.getElementById('zg-chart-cpu-memory'));
	var netChart = echarts.init(document.getElementById('zg-chart-network'));
	var diskChart = echarts.init(document.getElementById('zg-chart-disk'));
	var refreshTimer = null;
	var premiumState = {
		cy: null,
		layoutMode: 'cose',
		graph: { nodes: [], edges: [] },
		saveTimer: null
	};

	function fmtTime(ts) {
		var d = new Date(ts);
		return d.toLocaleTimeString();
	}

	function esc(v) {
		return String(v == null ? '' : v)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function zoneMarkArea(zones) {
		return (zones || []).map(function(z) {
			return [{ yAxis: z.from, itemStyle: { color: z.color } }, { yAxis: z.to }];
		});
	}

	function eventMarkPoints(points) {
		return (points || []).slice(0, 18).map(function(pt) {
			var sev = (pt.severity || '').toLowerCase();
			var color = sev === 'critical' || sev === 'disaster' ? '#dc3545' : (sev === 'warning' ? '#f0ad4e' : '#28a745');
			return {
				name: pt.label,
				coord: [pt.ts, null],
				value: pt.label,
				itemStyle: { color: color },
				label: { show: false }
			};
		});
	}

	function renderIssues(issues) {
		var wrap = document.getElementById('zg-hostobs-issues');
		if (!wrap) return;
		if (!issues || !issues.length) {
			wrap.innerHTML = '<div class="zg-hostobs-empty">No active issues</div>';
			return;
		}
		var rows = issues.map(function(issue) {
			return '<tr><td>#' + esc(issue.triggerid) + '</td><td>' + esc(issue.description) + '</td><td>' + esc(issue.label) + '</td></tr>';
		}).join('');
		wrap.innerHTML = '<table class="zg-hostobs-table"><thead><tr><th>ID</th><th>Issue</th><th>Severity</th></tr></thead><tbody>' + rows + '</tbody></table>';
	}

	function renderDetectedAlerts(alerts) {
		var wrap = document.getElementById('zg-hostobs-detected-alerts');
		if (!wrap) return;
		if (!alerts || !alerts.length) {
			wrap.innerHTML = '<div class="zg-hostobs-empty">No auto alerts detected</div>';
			return;
		}
		var rows = alerts.map(function(alert) {
			return '<tr>'
				+ '<td>' + esc(alert.metric || '-') + '</td>'
				+ '<td>' + esc(alert.severity || '-') + '</td>'
				+ '<td>' + esc(alert.reason || alert.title || '-') + '</td>'
				+ '</tr>';
		}).join('');
		wrap.innerHTML = '<table class="zg-hostobs-table"><thead><tr><th>Metric</th><th>Level</th><th>Why Triggered</th></tr></thead><tbody>' + rows + '</tbody></table>';
	}

	function renderTimeline(timeline) {
		var wrap = document.getElementById('zg-hostobs-timeline');
		if (!wrap) return;
		if (!timeline || !timeline.length) {
			wrap.innerHTML = '<div class="zg-hostobs-empty">No timeline events</div>';
			return;
		}
		wrap.innerHTML = timeline.slice(-12).map(function(t) {
			return '<div class="zg-hostobs-timeline-row"><span>' + esc(fmtTime((t.ts || 0) * 1000)) + '</span><span>' + esc(t.label) + '</span></div>';
		}).join('');
	}

	function renderBadges(badges) {
		var wrap = document.getElementById('zg-hostobs-badges');
		if (!wrap) return;
		wrap.innerHTML = (badges || []).map(function(b) {
			return '<span class="zg-hostobs-badge">' + esc(b.icon || '') + ' ' + esc(b.label || '') + '</span>';
		}).join('');
	}

	function drawCharts(payload) {
		var overlays = payload.overlays || {};
		var points = overlays.points || [];
		var zones = overlays.zones || [];
		var cpu = ((payload.metrics || {}).cpu || {}).series || [];
		var mem = ((payload.metrics || {}).memory || {}).series || [];
		var netIn = ((payload.metrics || {}).network_in || {}).series || [];
		var netOut = ((payload.metrics || {}).network_out || {}).series || [];
		var disk = ((payload.metrics || {}).disk || {}).series || [];

		cpuMemChart.setOption({
			animationDuration: 350,
			tooltip: {
				trigger: 'axis'
			},
			xAxis: { type: 'time' },
			yAxis: { type: 'value', max: 100, name: '%' },
			series: [
				{
					name: 'CPU',
					type: 'line',
					smooth: true,
					data: cpu.map(function(p) { return [p.ts, p.value]; }),
					lineStyle: { color: '#dc3545', width: 2 },
					markArea: { silent: true, data: zoneMarkArea(zones) },
					markPoint: { symbolSize: 10, data: eventMarkPoints(points) }
				},
				{
					name: 'Memory',
					type: 'line',
					smooth: true,
					data: mem.map(function(p) { return [p.ts, p.value]; }),
					lineStyle: { color: '#f0ad4e', width: 2 }
				}
			]
		}, true);

		netChart.setOption({
			animationDuration: 350,
			tooltip: { trigger: 'axis' },
			xAxis: { type: 'time' },
			yAxis: { type: 'value', name: 'bps' },
			series: [
				{ name: 'Network In', type: 'line', smooth: true, data: netIn.map(function(p) { return [p.ts, p.value]; }), lineStyle: { color: '#17a2b8', width: 2 } },
				{ name: 'Network Out', type: 'line', smooth: true, data: netOut.map(function(p) { return [p.ts, p.value]; }), lineStyle: { color: '#6f42c1', width: 2 } }
			]
		}, true);

		diskChart.setOption({
			animationDuration: 350,
			tooltip: { trigger: 'axis' },
			xAxis: { type: 'time' },
			yAxis: { type: 'value', max: 100, name: '%' },
			series: [
				{ name: 'Disk', type: 'line', smooth: true, areaStyle: {}, data: disk.map(function(p) { return [p.ts, p.value]; }), lineStyle: { color: '#198754', width: 2 } }
			]
		}, true);
	}

	function mapStatus(text) {
		var el = document.getElementById('zg-premium-chat-status');
		if (el) {
			el.textContent = text;
		}
	}

	function appendChat(role, text) {
		var wrap = document.getElementById('zg-premium-chat-log');
		if (!wrap) return;
		var row = document.createElement('div');
		row.className = 'zg-premium-chat-row ' + role;
		row.textContent = text;
		wrap.appendChild(row);
		wrap.scrollTop = wrap.scrollHeight;
	}

	function mapColorByStatus(status) {
		if (status === 'critical') return '#dc3545';
		if (status === 'warning') return '#f39b14';
		if (status === 'healthy') return '#198754';
		return '#2f7fbd';
	}

	function buildMapElements(graph) {
		var elements = [];
		(graph.nodes || []).forEach(function(node) {
			elements.push({
				data: {
					id: node.id,
					label: node.label || node.id,
					type: node.type || 'node',
					status: node.status || 'normal'
				}
			});
		});
		(graph.edges || []).forEach(function(edge, idx) {
			elements.push({
				data: {
					id: 'pe_' + idx + '_' + edge.source + '_' + edge.target,
					source: edge.source,
					target: edge.target,
					label: edge.label || ''
				}
			});
		});
		return elements;
	}

	function mergeGraph(base, extra) {
		var nodes = [].concat((base.nodes || []), (extra.nodes || []));
		var edges = [].concat((base.edges || []), (extra.edges || []));
		var seenNodes = {};
		var seenEdges = {};
		var dedupNodes = [];
		var dedupEdges = [];

		nodes.forEach(function(n) {
			if (!n || !n.id || seenNodes[n.id]) return;
			seenNodes[n.id] = true;
			dedupNodes.push(n);
		});

		edges.forEach(function(e) {
			if (!e || !e.source || !e.target) return;
			var key = e.source + '>' + e.target + '>' + (e.label || '');
			if (seenEdges[key]) return;
			seenEdges[key] = true;
			dedupEdges.push(e);
		});

		return { nodes: dedupNodes, edges: dedupEdges };
	}

	function applyLayout(layout) {
		if (!premiumState.cy || !layout || !layout.nodes) return;
		Object.keys(layout.nodes).forEach(function(nodeId) {
			var pos = layout.nodes[nodeId];
			var node = premiumState.cy.getElementById(nodeId);
			if (node && node.length && pos && typeof pos.x === 'number' && typeof pos.y === 'number') {
				node.position({ x: pos.x, y: pos.y });
			}
		});
		if (layout.pan && typeof layout.pan.x === 'number' && typeof layout.pan.y === 'number') {
			premiumState.cy.pan({ x: layout.pan.x, y: layout.pan.y });
		}
		if (typeof layout.zoom === 'number') {
			premiumState.cy.zoom(layout.zoom);
		}
	}

	function saveMapLayout() {
		if (!premiumState.cy || !mapLayoutSaveUrl) return;
		var nodes = {};
		premiumState.cy.nodes().forEach(function(node) {
			var p = node.position();
			nodes[node.id()] = { x: p.x, y: p.y };
		});

		var payload = {
			hostid: hostid,
			layout: JSON.stringify({
				pan: premiumState.cy.pan(),
				zoom: premiumState.cy.zoom(),
				nodes: nodes
			})
		};

		jQuery.ajax({
			url: mapLayoutSaveUrl,
			type: 'POST',
			dataType: 'json',
			contentType: 'application/json',
			data: JSON.stringify(payload)
		});
	}

	function scheduleLayoutSave() {
		if (premiumState.saveTimer) {
			clearTimeout(premiumState.saveTimer);
		}
		premiumState.saveTimer = setTimeout(saveMapLayout, 500);
	}

	function renderPremiumGraph(graph, layout) {
		var canvas = document.getElementById('zg-premium-map-canvas');
		var canvasWrap = document.querySelector('.zg-premium-map-canvas-wrap');
		if (!canvas) return;
		if (typeof cytoscape === 'undefined') {
			canvas.innerHTML = '<div class="zg-hostobs-empty">Graph engine unavailable.</div>';
			return;
		}
		if (canvasWrap) {
			canvasWrap.classList.add('is-loading');
		}

		if (premiumState.cy) {
			premiumState.cy.destroy();
		}

		premiumState.graph = mergeGraph({ nodes: [], edges: [] }, graph || {});
		premiumState.cy = cytoscape({
			container: canvas,
			elements: buildMapElements(premiumState.graph),
			style: [
				{
					selector: 'node',
					style: {
						'label': 'data(label)',
						'background-color': function(ele) { return mapColorByStatus(ele.data('status')); },
						'font-size': 10,
						'width': 24,
						'height': 24,
						'color': '#1d3550',
						'text-wrap': 'wrap',
						'text-max-width': 120,
						'text-valign': 'bottom',
						'text-margin-y': 8,
						'border-width': 1,
						'border-color': '#e8f0f8'
					}
				},
				{
					selector: '.is-dim',
					style: {
						'opacity': 0.18
					}
				},
				{
					selector: '.is-focus',
					style: {
						'opacity': 1,
						'line-color': '#1e88d7',
						'target-arrow-color': '#1e88d7',
						'width': 2.6,
						'background-color': '#1e88d7'
					}
				},
				{
					selector: 'edge',
					style: {
						'label': 'data(label)',
						'curve-style': 'bezier',
						'line-color': '#7fa7c8',
						'target-arrow-color': '#7fa7c8',
						'target-arrow-shape': 'triangle',
						'arrow-scale': 0.7,
						'font-size': 8,
						'color': '#537592'
					}
				}
			],
			layout: {
				name: premiumState.layoutMode,
				animate: true,
				padding: 24,
				randomize: false
			}
		});

		applyLayout(layout || {});

		premiumState.cy.on('dragfree', 'node', scheduleLayoutSave);
		premiumState.cy.on('pan zoom', scheduleLayoutSave);
		premiumState.cy.on('tap', 'node', function(evt) {
			var d = evt.target.data();
			appendChat('ai', 'Focused node: ' + (d.label || d.id) + ' [' + (d.type || 'node') + ']');
		});

		premiumState.cy.on('mouseover', 'node', function(evt) {
			var node = evt.target;
			premiumState.cy.elements().addClass('is-dim').removeClass('is-focus');
			node.removeClass('is-dim').addClass('is-focus');
			node.connectedEdges().removeClass('is-dim').addClass('is-focus');
			node.connectedEdges().connectedNodes().removeClass('is-dim').addClass('is-focus');
		});

		premiumState.cy.on('mouseout', 'node', function() {
			premiumState.cy.elements().removeClass('is-dim').removeClass('is-focus');
		});

		if (canvasWrap) {
			canvasWrap.classList.remove('is-loading');
		}
	}

	function fetchPremiumMap() {
		if (!premiumEnabled || !mapDataUrl) return;
		var canvasWrap = document.querySelector('.zg-premium-map-canvas-wrap');
		if (canvasWrap) {
			canvasWrap.classList.add('is-loading');
		}
		mapStatus('Building map...');
		jQuery.ajax({
			url: mapDataUrl + (mapDataUrl.indexOf('?') >= 0 ? '&' : '?') + 'hostid=' + encodeURIComponent(hostid),
			type: 'GET',
			dataType: 'json'
		}).done(function(resp) {
			if (!resp || !resp.success) {
				mapStatus('Map unavailable');
				return;
			}
			renderPremiumGraph(resp.graph || {}, resp.layout || {});
			if (resp.summary) {
				appendChat('ai', resp.summary);
			}
			mapStatus('Map ready');
		}).always(function() {
			if (canvasWrap) {
				canvasWrap.classList.remove('is-loading');
			}
		});
	}

	function bindPremiumTools() {
		var fitBtn = document.getElementById('zg-premium-fit');
		if (fitBtn) {
			fitBtn.addEventListener('click', function() {
				if (premiumState.cy) {
					premiumState.cy.fit(undefined, 26);
				}
			});
		}

		jQuery(document).off('click.zgPremiumLayout', '.zg-premium-layout-btn').on('click.zgPremiumLayout', '.zg-premium-layout-btn', function() {
			premiumState.layoutMode = jQuery(this).attr('data-layout') || 'cose';
			jQuery('.zg-premium-layout-btn').removeClass('is-active');
			jQuery(this).addClass('is-active');
			renderPremiumGraph(premiumState.graph, {});
		});
	}

	function bindPremiumChat() {
		var form = document.getElementById('zg-premium-chat-form');
		var input = document.getElementById('zg-premium-question');
		if (!form || !input) return;
		form.addEventListener('submit', function(e) {
			e.preventDefault();
			var question = input.value.trim();
			if (!question || !mapChatUrl) return;
			appendChat('user', question);
			mapStatus('Analyzing...');
			jQuery.ajax({
				url: mapChatUrl,
				type: 'POST',
				dataType: 'json',
				contentType: 'application/json',
				data: JSON.stringify({
					hostid: hostid,
					question: question,
					context_graph: premiumState.graph
				})
			}).done(function(resp) {
				if (!resp || !resp.success) {
					appendChat('ai', (resp && resp.error) ? resp.error : 'Unable to analyze right now.');
					mapStatus('Error');
					return;
				}
				if (resp.graph) {
					renderPremiumGraph(resp.graph, {});
				}
				appendChat('ai', resp.message || 'Analysis complete and map updated.');
				if (resp.insight) {
					appendChat('ai', 'Insight: ' + resp.insight);
				}
				mapStatus('Ready');
				input.value = '';
			});
		});
	}

	function bindPremiumUpload() {
		var form = document.getElementById('zg-premium-upload-form');
		var fileInput = document.getElementById('zg-premium-upload-file');
		var textInput = document.getElementById('zg-premium-upload-text');
		if (!form || !mapUploadUrl) return;

		form.addEventListener('submit', function(e) {
			e.preventDefault();
			var fd = new FormData();
			fd.append('hostid', hostid);
			if (textInput && textInput.value.trim()) {
				fd.append('json_text', textInput.value.trim());
			}
			if (fileInput && fileInput.files && fileInput.files[0]) {
				fd.append('file', fileInput.files[0]);
			}

			mapStatus('Parsing upload...');
			jQuery.ajax({
				url: mapUploadUrl,
				type: 'POST',
				data: fd,
				contentType: false,
				processData: false,
				dataType: 'json'
			}).done(function(resp) {
				if (!resp || !resp.success) {
					appendChat('ai', (resp && resp.error) ? resp.error : 'Upload could not be parsed.');
					mapStatus('Error');
					return;
				}
				var merged = mergeGraph(premiumState.graph, resp.graph || {});
				renderPremiumGraph(merged, {});
				appendChat('ai', resp.message || 'Upload parsed and merged into the map.');
				mapStatus('Ready');
				if (textInput) textInput.value = '';
				if (fileInput) fileInput.value = '';
			});
		});
	}

	function renderPayload(payload) {
		var statusEl = document.getElementById('zg-hostobs-status');
		var summaryEl = document.getElementById('zg-hostobs-summary');
		var aiSummary = document.getElementById('zg-ai-summary');
		var aiRoot = document.getElementById('zg-ai-root');
		var aiImpact = document.getElementById('zg-ai-impact');
		var aiFix = document.getElementById('zg-ai-fix');

		if (statusEl) {
			statusEl.textContent = ((payload.status || {}).label) || '-';
			statusEl.className = 'zg-hostobs-status zg-status-' + (((payload.status || {}).level) || 'healthy');
		}
		if (summaryEl) {
			summaryEl.textContent = payload.alert_summary || '';
		}

		var insight = payload.insight || {};
		if (aiSummary) aiSummary.textContent = insight.summary || '-';
		if (aiRoot) aiRoot.textContent = insight.root_cause || '-';
		if (aiImpact) aiImpact.textContent = insight.impact || '-';
		if (aiFix) aiFix.textContent = insight.suggested_fix || '-';

		renderIssues(payload.issues || []);
		renderDetectedAlerts(payload.detected_alerts || []);
		renderTimeline(payload.timeline || []);
		renderBadges(payload.badges || []);
		drawCharts(payload);
	}

	function fetchAndRender() {
		var windowVal = document.getElementById('zg-hostobs-window').value;
		var severityVal = document.getElementById('zg-hostobs-severity').value;
		var metricVal = document.getElementById('zg-hostobs-metric').value;
		var url = dataUrl
			+ (dataUrl.indexOf('?') >= 0 ? '&' : '?')
			+ 'hostid=' + encodeURIComponent(hostid)
			+ '&window=' + encodeURIComponent(windowVal)
			+ '&severity=' + encodeURIComponent(severityVal)
			+ '&metric=' + encodeURIComponent(metricVal);

		jQuery.ajax({
			url: url,
			type: 'GET',
			dataType: 'json'
		}).done(function(resp) {
			if (!resp || !resp.success) {
				return;
			}
			renderPayload(resp);
		});
	}

	document.getElementById('zg-hostobs-refresh').addEventListener('click', function() {
		fetchAndRender();
	});
	document.getElementById('zg-hostobs-window').addEventListener('change', fetchAndRender);
	document.getElementById('zg-hostobs-severity').addEventListener('change', fetchAndRender);
	document.getElementById('zg-hostobs-metric').addEventListener('change', fetchAndRender);

	window.addEventListener('resize', function() {
		cpuMemChart.resize();
		netChart.resize();
		diskChart.resize();
		if (premiumState.cy) {
			premiumState.cy.resize();
			premiumState.cy.fit(undefined, 24);
		}
	});

	fetchAndRender();
	refreshTimer = setInterval(fetchAndRender, 12000);

	if (premiumEnabled) {
		fetchPremiumMap();
		bindPremiumTools();
		bindPremiumChat();
		bindPremiumUpload();
	}
})();
