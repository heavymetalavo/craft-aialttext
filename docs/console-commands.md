# 🖥️ Console commands

[← README](../README.md) · [Getting started](getting-started.md) · [AI providers](providers.md) · [Settings](settings.md) · **Console commands** · [Images](images.md) · [Costs](costs.md)

**Important**: `missing`, `all` and `some` create **queue** jobs which when run will generate the alt text, while `single` generates straight away (queueing the other sites when **Save translated results for each site** is enabled). By default, `stats`, `missing`, `all` and `some` process **all sites** unless `--site-id` is specified, while `single` generates for the primary site and the sites that share its alt text. Sites that share alt text under the volume's **Alternative Text Translation Method** (on a Not translatable volume, every site) are handled once: `missing` copies alt text one of them already has to the others, and otherwise one job generates it for all of them. Assets excluded by the **Excluded volumes** and **Excluded paths** settings are skipped by all of them, `single` included.

## Available Commands

| Command | Description |
|---------|-------------|
| `ai-alt-text/generate/stats` | Show alt text coverage statistics |
| `ai-alt-text/generate/missing` | Queue jobs for assets without alt text (recommended) |
| `ai-alt-text/generate/all` | Queue jobs for ALL assets (⚠️ overwrites existing alt text) |
| `ai-alt-text/generate/some <ids>` | Queue jobs for the listed asset IDs, e.g. `71,72,83` (⚠️ overwrites existing alt text) |
| `ai-alt-text/generate/single <id>` | Generate for a specific asset ID straight away, for the primary site unless `-s` is given |

## Options

| Option | Alias | Description | Default |
|--------|-------|-------------|---------|
| `--site-id=<id>` | `-s` | Process only specific site | All sites (`single`: the primary site) |
| `--volume=<handle>` | | Process only this volume's assets (`missing` and `all`), instead of every volume | Every volume |
| `--path=<path>` | | Process only a folder or files in the `--volume` (`missing` and `all`), written like an [excluded path](settings.md): `staff` for a folder and everything in it, `*` as a wildcard. Needs `--volume` | The whole volume |
| `--batch-size=<n>` | `-b` | Assets per batch (memory efficiency) (`missing`, `all` and `some`) | `500` |
| `--verbose` | `-v` | Show detailed progress (`missing`, `all` and `some`) | `false` |
| `--force` | `-f` | Skip confirmations (`missing`, `all` and `some`) | `false` |

## Examples

```sh
# Check coverage across all sites
./craft ai-alt-text/generate/stats

# Queue missing alt text for all sites
./craft ai-alt-text/generate/missing

# Queue for specific site with verbose output
./craft ai-alt-text/generate/missing --site-id=2 --verbose

# Queue missing alt text for one volume
./craft ai-alt-text/generate/missing --volume=images

# Queue every image in a folder of a volume, and the folders in it, regenerating existing alt text
./craft ai-alt-text/generate/all --volume=images --path=staff

# Queue missing alt text for the PNGs in a volume (quote the * so the shell leaves it alone)
./craft ai-alt-text/generate/missing --volume=images --path='*.png'

# Queue several assets by ID, regenerating any that already have alt text
./craft ai-alt-text/generate/some 71,72,83

# Generate for a single asset
./craft ai-alt-text/generate/single 123

# Generate for a single asset on a specific site
./craft ai-alt-text/generate/single 123 --site-id=2
```
