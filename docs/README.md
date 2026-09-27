# MM Optimizer documentation

Technical reference for the plugin. For installation and day-to-day use, see
the [wiki](https://github.com/inalto/mm-optimizer/wiki).

| Document | Covers |
|---|---|
| [Detection](detection.md) | The five-stage capability engine, why it distinguishes claimed from verified support, and how the cache is invalidated |
| [Optimization levels](optimization-levels.md) | The four presets, the measurements behind the numbers, and the safety rules applied at every level |
| [Delivery](delivery.md) | Responsive `<picture>` output, the all-or-nothing srcset rule, `.htaccess` negotiation, and the AVIF MIME-type trap |
| [Conflicts](conflicts.md) | How competing plugins are detected and which subsystems stand down |
| [Hooks and API](hooks.md) | Filters, public functions, and the WordPress hooks the plugin registers |
| [Database](database.md) | Schema of the two tables and why each column exists |
| [Troubleshooting](troubleshooting.md) | Reading the diagnostics screen and what each failure code means |

## Design principles

Three rules shape every decision in this codebase.

**Never trust declared support.** A library that says it can encode a format is
not evidence that it can. The plugin encodes a known sample and inspects the
result before enabling anything.

**Never modify an original.** The plugin writes sibling derivatives and touches
only the thumbnails WordPress itself generated. The original is the last intact
copy; rewriting it makes any mistake irreversible.

**Explain every recommendation.** A proposed setting without a reason is just
another default. Each one carries the measurement or condition that produced it.
