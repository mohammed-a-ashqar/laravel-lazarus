# Recording the demo: break an app, get a pull request

These steps build the 60-second demo from scratch in a fresh Laravel application: a page that
crashes with a divide-by-zero, and a verified pull request that fixes it. Plan on ten minutes of
setup; the recording itself is about a minute.

## 1. A fresh app on GitHub

```bash
laravel new lazarus-demo --pest --no-interaction
cd lazarus-demo
git init -b main && git add -A && git commit -m "Fresh Laravel app"
```

Create an empty repository on GitHub (for example `your-name/lazarus-demo`) and push to it:

```bash
git remote add origin git@github.com:your-name/lazarus-demo.git
git push -u origin main
```

## 2. Install Lazarus

From Packagist:

```bash
composer require mohammedname2002/laravel-lazarus
```

Or from a local checkout next to the app, while developing:

```bash
composer config repositories.lazarus '{"type": "path", "url": "../laravel-lazarus"}'
composer require "mohammedname2002/laravel-lazarus:@dev"
```

Then:

```bash
php artisan vendor:publish --tag=lazarus-config
php artisan migrate
```

## 3. Configure it

Add to `.env` (the model and the GitHub token are the only secrets):

```dotenv
LAZARUS_LLM=anthropic
ANTHROPIC_API_KEY=sk-ant-...

LAZARUS_PUBLISHER=github
LAZARUS_GITHUB_TOKEN=github_pat_...        # fine-grained: Contents + Pull requests, read/write, this repo only
LAZARUS_GITHUB_REPOSITORY=your-name/lazarus-demo
```

Prefer to keep everything on your machine? Use Ollama instead, at no cost:

```bash
ollama pull qwen2.5-coder
```

```dotenv
LAZARUS_LLM=ollama
```

To record without a GitHub token, leave `LAZARUS_PUBLISHER` unset: the fix is written to
`storage/lazarus/*.patch` with a Markdown report next to it.

## 4. Plant the bug

`app/Services/InvoiceCalculator.php`:

```php
<?php

namespace App\Services;

class InvoiceCalculator
{
    /**
     * @param  list<array{price: int, quantity: int}>  $lines  Prices in cents.
     */
    public function total(array $lines): int
    {
        return array_sum(array_map(fn (array $line) => $line['price'] * $line['quantity'], $lines));
    }

    /**
     * The average price paid per unit, in cents.
     */
    public function averageUnitPrice(array $lines): float
    {
        $quantity = array_sum(array_column($lines, 'quantity'));

        return $this->total($lines) / $quantity;
    }
}
```

Append to `routes/web.php`:

```php
use App\Services\InvoiceCalculator;

Route::get('/invoices/{invoice}/average', function (string $invoice, InvoiceCalculator $calculator) {
    $lines = match ($invoice) {
        'free-sample' => [['price' => 1200, 'quantity' => 0]],
        default => [['price' => 1000, 'quantity' => 1], ['price' => 2000, 'quantity' => 3]],
    };

    return ['average_unit_price' => $calculator->averageUnitPrice($lines)];
});
```

Give the app a test that covers the happy path, so the "full suite passes" proof means something.
`tests/Feature/InvoiceAverageTest.php`:

```php
<?php

it('averages the unit price of an invoice', function () {
    $this->get('/invoices/regular/average')->assertOk()->assertJson(['average_unit_price' => 1750]);
});
```

Commit and push: Lazarus heals `HEAD` and refuses to work on a dirty tree.

```bash
php artisan test
git add -A && git commit -m "Add invoice averages" && git push
```

## 5. Check the setup

```bash
php artisan lazarus:doctor
```

Every line should say `OK`. `Working tree is clean` and `Auto-heal` may say `WARN`.

## 6. Record

Use a large terminal font and a browser side by side.

1. **Break it.** `php artisan serve`, then open `http://127.0.0.1:8000/invoices/free-sample/average`.
   Laravel's error page shows `DivisionByZeroError: Division by zero`.
2. **See the incident.** `php artisan lazarus:list` shows incident `#1`, captured and redacted.
3. **Heal it.** `php artisan lazarus:heal 1`. The steps scroll by live: the worktree, the red run
   with the original exception, the patch, the green run, the full suite, the diagnosis.
4. **The pull request arrives.** Open the link printed at the end: a draft PR titled
   `fix: ...` with the diagnosis, the reproduction test, the diff, and the red and green output.
5. **Show that nothing was touched.** `git status` is clean and `git branch` only lists `main`:
   the work happened in a throwaway worktree that is already gone.

Reset between takes:

```bash
php artisan migrate:fresh                        # forget the incidents
git push origin --delete lazarus/fix-<prefix>    # the pushed branch (shown in the PR), then close the PR
```

## Troubleshooting

- **"The working tree has uncommitted changes"**: commit or stash first.
- **The reproduction keeps failing for another reason**: run `php artisan test` in the app; the
  suite has to pass on `main` before a fix can be proven.
- **Feature tests fail only inside the worktree**: something they need is untracked (for example
  `public/build` from `npm run build`). Add it to `sandbox.copy_files` or use `withoutVite()`.
- **Nothing is captured**: check `APP_ENV` is in `lazarus.environments` and that the exception class
  isn't in `lazarus.ignore`.
