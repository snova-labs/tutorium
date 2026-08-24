# Livewire v4 patch

I flagged this as an open risk when shipping the Filament panel and then checked
it against the official upgrade guide rather than leaving it as a warning.
**Two of the three teacher-facing screens genuinely break.** Both fixes are in
this package.

## What actually breaks

### 1. Full-page components need `Route::livewire()`

From the v4 upgrade guide: `Route::livewire()` *"is now the preferred method and
is required for single-file and multi-file components to work correctly as
full-page components."*

`Route::get('/', Today::class)` becomes `Route::livewire('/', Today::class)`.
Affects all three screens. **Fixed in `routes/web.php` below.**

### 2. `.blur` changed meaning

Also from the guide: *"Modifiers like `.blur` and `.change` now control when
client-side state syncs, not just network timing. If you're using these
modifiers and want the previous behavior, add `.live` before them."*

The register uses `wire:model.blur` on the per-learner note field. Under v4 that
no longer syncs to the server the way it did, so a note typed and then saved
would silently not arrive — the worst kind of break, because nothing errors.

`wire:model.blur` becomes `wire:model.live.blur`. **Fixed in the register view
below.**

## What does *not* break, having checked

- **`#[Computed]`, `#[Locked]`, `#[Url]`** — unchanged in v4.
- **`wire:model` on form inputs.** v4 stops `wire:model` listening to events
  bubbling up from child elements, but the guide is explicit that *"standard
  form input bindings (inputs, selects, textareas) are unaffected."* Every use
  here is a direct binding on an input or select.
- **`wire:model.live.debounce.400ms`** on the gradebook — `.live` is already
  explicit, so it is unaffected by the `.blur` change.
- **Class components with a separate view.** v4 defaults new components to
  single-file format, but the classic class plus `render()` returning a view is
  still supported. No need to move anything out of `resources/views/livewire/`.
- **`@script`** — not used anywhere here.

## Applying it

```bash
cd ~/code/platform
unzip -o ~/Downloads/livewire-v4-and-resources.zip

# The two files above are replaced. Then:
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan test --filter=Ui
```

If `config/livewire.php` was published before the upgrade, republish it — some
keys were renamed:

```bash
docker compose exec app php artisan vendor:publish --tag=livewire-config --force
```
