# 🖥️ Console commands

[← README](../README.md) · [Getting started](getting-started.md) · [AI providers](providers.md) · [Settings](settings.md) · **Console commands** · [Images](images.md) · [Costs](costs.md)

**Important**: `missing` and `all` create **queue** jobs which when run will generate the alt text, while `single` generates straight away (queueing the other sites when **Save translated results for each site** is enabled). By default, all commands process **all sites** unless `--site-id` is specified. Sites that share alt text under the volume's **Alternative Text Translation Method** (on a Not translatable volume, every site) are handled once: `missing` copies alt text one of them already has to the others, and otherwise one job generates it for all of them. `missing` and `all` skip assets excluded by the **Excluded volumes**, **Excluded folders** and **Excluded sections** settings, and `single` doesn't.

## Available Commands

| Command | Description |
|---------|-------------|
| `ai-alt-text/generate/stats` | Show alt text coverage statistics |
| `ai-alt-text/generate/missing` | Queue jobs for assets without alt text (recommended) |
| `ai-alt-text/generate/all` | Queue jobs for ALL assets (⚠️ overwrites existing alt text) |
| `ai-alt-text/generate/single <id>` | Generate for a specific asset ID straight away (use `-s` for one site) |

## Options

| Option | Alias | Description | Default |
|--------|-------|-------------|---------|
| `--site-id=<id>` | `-s` | Process only specific site (if not set, processes all sites) | * |
| `--batch-size=<n>` | `-b` | Assets per batch (memory efficiency) | `500` |
| `--verbose` | `-v` | Show detailed progress | `false` |
| `--force` | `-f` | Skip confirmations | `false` |

## Examples

```sh
# Check coverage across all sites
./craft ai-alt-text/generate/stats

# Queue missing alt text for all sites
./craft ai-alt-text/generate/missing

# Queue for specific site with verbose output
./craft ai-alt-text/generate/missing --site-id=2 --verbose

# Generate for a single asset
./craft ai-alt-text/generate/single 123

# Generate for a single asset on a specific site
./craft ai-alt-text/generate/single 123 --site-id=2
```
