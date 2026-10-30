# 🖥️ Console commands

[← README](../README.md) · [Getting started](getting-started.md) · [AI providers](providers.md) · [Settings](settings.md) · **Console commands** · [Images](images.md) · [Costs](costs.md)

**Important**: `missing` and `all` create **queue** jobs which when run will generate the alt text, while `single` generates straight away (queueing the other sites when **Save translated results for each site** is enabled). By default, all commands process **all sites** unless `--site-id` is specified. Sites that share alt text under the volume's **Alternative Text Translation Method** (on a Not translatable volume, every site) are handled once: `missing` copies alt text one of them already has to the others, and otherwise one job generates it for all of them.

## Available Commands

| Command | Description |
|---------|-------------|
| `ai-alt-text:stats` | Show alt text coverage statistics |
| `ai-alt-text:missing` | Queue jobs for assets without alt text (recommended) |
| `ai-alt-text:all` | Queue jobs for ALL assets (⚠️ overwrites existing alt text) |
| `ai-alt-text:single <id>` | Generate for a specific asset ID straight away (use `--site-id` for one site) |

## Options

| Option | Description | Default | Accepted by |
|--------|-------------|---------|-------------|
| `--site-id=<id>` | Process only a specific site (if not set, processes all sites) | * | all commands |
| `--batch-size=<n>` | Assets per batch (memory efficiency) | `500` | `missing`, `all` |
| `--force` | Skip confirmation prompts. Which assets get regenerated is determined by the command, not this flag | `false` | `missing`, `all` |
| `-v` | Show detailed progress (Laravel's global verbosity flag) | `false` | `missing`, `all` |

## Examples

```sh
# Check coverage across all sites
php artisan ai-alt-text:stats

# Queue missing alt text for all sites
php artisan ai-alt-text:missing

# Queue for specific site with verbose output
php artisan ai-alt-text:missing --site-id=2 -v

# Generate for a single asset
php artisan ai-alt-text:single 123

# Generate for a single asset on a specific site
php artisan ai-alt-text:single 123 --site-id=2
```

Then run the queue to process the jobs:

```sh
php artisan queue:work
```
