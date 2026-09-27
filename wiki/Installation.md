# Installation

## From a release

1. Download `mm-optimizer.zip` from the
   [latest release](https://github.com/inalto/mm-optimizer/releases/latest).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Choose the zip and click **Install Now**, then **Activate**.

## From source

```bash
cd wp-content/plugins
git clone https://github.com/inalto/mm-optimizer.git
```

Then activate it from the Plugins screen, or with WP-CLI:

```bash
wp plugin activate mm-optimizer
```

There is no build step and no Composer install: the plugin ships ready to run.

If you clone into a live document root, make sure the `.git` directory is not
web-readable. A `Require all denied` block inside it, or a server rule blocking
dotted directories, is enough.

## First run

On activation the plugin schedules a detection pass for 30 seconds later, so by
the time you open **MM Optimizer** in the admin menu the results are usually
waiting. If the screen looks empty, click **Run detection again**.

Detection takes around half a second. It encodes small test images to verify
your server's libraries actually work; see [Diagnostics](Diagnostics).

## Recommended first steps

1. Open **MM Optimizer → Diagnostics**.
2. Read the score and the reasons beneath it.
3. Click **Apply the recommended configuration**.
4. Go to **Bulk conversion** and start converting the images you already have.

## Verifying with WP-CLI

```bash
# What can this server do?
wp eval 'print_r( mm_optimizer_capabilities() );'

# What does the plugin recommend, and why?
wp eval 'print_r( mm_optimizer_recommendation() );'

# Can it genuinely produce AVIF?
wp eval 'var_dump( mm_optimizer_supports( "avif" ) );'
```

## Uninstalling

Deactivating removes nothing. Deleting the plugin removes nothing either, unless
you first enable **Uninstall** on the Advanced tab. That is deliberate: your
converted images should outlive a plugin you were only trying out.
