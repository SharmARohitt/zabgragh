# Premium Knowledge Graph / Chat Panel Plan

## Goal
Add a separate, optional ultra-premium left-panel experience to the existing Host Observability module. The current host charts, polling, and alert logic must remain unchanged. The new surface should feel like a chat-driven knowledge graph studio where users can ask questions about Zabbix data or uploaded JSON and then see the result as a clean, draggable map.

## Core Approach
- Keep the existing host observability flow as the default experience.
- Add a feature-flagged premium left dock that only renders when enabled.
- Reuse the module's existing graph patterns, especially Cytoscape-based interactions from the ZabGraph workspace, for node drag, pan, zoom, and layout persistence.
- Treat the premium panel as additive: new controllers, new payload keys, new JS init path, and new CSS classes. Do not reshape the current observability payload.

## Files To Touch
- manifest.json: register new premium endpoints and keep the current routes intact.
- actions/CControllerVoiceAIHostGraphs.php: pass feature flag and premium endpoint URLs into the view.
- actions/CControllerVoiceAIHostGraphsData.php: keep stable; only add optional premium metadata if needed.
- views/voiceai.hostgraphs.view.php: render the premium shell, left dock, chat heading, upload controls, and graph canvas when enabled.
- views/js/voiceai.hostgraphs.view.js.php: initialize the premium panel lazily and keep existing chart refresh logic unchanged.
- services/HostObservabilityService.php: keep observability payload generation focused and stable.
- services/GeminiInsightService.php: add structured Q&A support for host context, graph context, and attachment excerpts.
- New service/controller files for premium chat, upload parsing, graph normalization, and layout save.
- assets/css/blue-theme.css and assets/css/dark-theme.css: add new premium panel styles only.

## Implementation Phases
1. Wire the feature flag and endpoint URLs through the controller and manifest.
2. Add the premium UI shell in the view, but keep it hidden unless enabled.
3. Build the premium graph model and render it with Cytoscape so nodes can be moved and saved.
4. Add upload support, with JSON as the first-class format, and normalize file data into graph nodes and edges.
5. Add chat/Q&A handling so user prompts can be converted into graph insight, not just plain text.
6. Style the premium column to feel distinct, polished, and intentionally high-end while preserving the current observability look.

## Data / UX Rules
- Existing host charts, alerts, summaries, and refresh intervals must continue to work exactly as they do now when the premium feature is off.
- Uploaded JSON should be parsed server-side and mapped into graph structure deterministically.
- The graph should support drag/reposition behavior and saving layout separately from the current observability UI.
- The chat surface should accept freeform questions and return a combined answer plus graph-friendly structure.
- The premium panel should be visually dense, clear, and map-like, with a left-panel heading, control area, and graph canvas.

## Verification
- Confirm the host observability page still loads with no premium flag enabled.
- Confirm the existing data endpoint still returns the same keys and the current JS still renders charts.
- Confirm the premium panel appears only when enabled.
- Confirm JSON upload parsing produces nodes and edges without breaking existing UI.
- Confirm draggable node updates persist and do not affect the current observability panel.
- Confirm the layout works in both light and dark themes.
