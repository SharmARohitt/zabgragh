# ZabGraph
![views](https://komarev.com/ghpvc/?username=rohitsharma&repo=https://github.com/rohitsharma/ZabGraph) 
![Stars](https://img.shields.io/github/stars/rohitsharma/ZabGraph?style=social)
![Forks](https://img.shields.io/github/forks/rohitsharma/ZabGraph?style=social)
![Issues](https://img.shields.io/github/issues/rohitsharma/ZabGraph)

Zabbix module that provides a visual correlation view of Problems, Triggers and Actions. The workflow shows the relationship Host → Item → Trigger → Problem → Actions → Media types → Recipients.

<img width="1457" height="761" alt="image" src="https://github.com/user-attachments/assets/72c6738e-fbae-426d-99f4-efaee4708e86" />



## Requirements

- Zabbix 7.0 or later
- PHP 8.0 or later

Service impact (SLA/SLI) and temporal analysis heatmap require the Zabbix Services module.

## Installation

1. Copy the `ZabGraph` folder to `/usr/share/zabbix/modules/`
2. Restart the Zabbix frontend or clear the cache
3. The module is loaded automatically
4. Access: icon on Monitoring → Problems or via `?action=zabgraph.view`

## Features

- **List view**: problems with filters (severity, acknowledged, resolved)
- **Single view**: interactive canvas with pan/zoom, draggable nodes, persisted layout
- **Heatmap**: temporal analysis (12 months) in the Problem card
- **Services**: node with SLI donuts and impacted service tree
- **Popup**: modal from the icon in Problems tables
- **Export PDF**: export the workflow to PDF
- **Themes**: light and dark theme support

## Flow structure

- **Phase 1**: Template, Group, Host, Maintenance, Maps, Dashboard, Items, Triggers, Dependencies
- **Phase 2**: Problem, Services, Acknowledgments, Actions, Media types, Recipients, Resolved

## License

GPL v2 (compatible with Zabbix)

## Author

Rohit Sharma - https://github.com/rohitsharma
