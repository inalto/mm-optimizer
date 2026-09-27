# MM Optimizer

Image optimization for WordPress that begins by asking what your server can
actually do, then proposes settings to match.

Most optimization plugins present a wall of switches and leave you to guess.
This one runs a detection pass, shows you a capability matrix built from real
encoding tests, and recommends a configuration with the reason next to every
choice.

**It never modifies your original files.**

## Start here

| Page | For |
|---|---|
| [Installation](Installation) | Getting it running |
| [The Diagnostics screen](Diagnostics) | Reading what your server can do |
| [Optimization levels](Optimization-levels) | Choosing how hard to compress |
| [Delivering images](Delivering-images) | Getting WebP and AVIF to browsers |
| [Bulk conversion](Bulk-conversion) | Converting a library you already have |
| [Migrating from another plugin](Migrating) | Coming from Converter for Media or EWWW |
| [FAQ](FAQ) | Short answers |

Developers: the technical reference lives in
[`docs/`](https://github.com/inalto/mm-optimizer/tree/main/docs).

## What it produces

Measured on a real 2048×1367 photograph of 721,366 bytes at the Balanced level:

| Format | Size | Saving |
|---|---|---|
| AVIF | 244,780 B | **−66%** |
| WebP | 380,730 B | **−47%** |

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later
- GD or Imagick with WebP and/or AVIF support — the plugin tells you which you
  have, and what to ask your host for if you have neither
