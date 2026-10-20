<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Support;

use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Facades\Auditor;
use Ipsocode\Auditing\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;

class ConfigFallbackTest extends TestCase
{
    public function testEveryInlineFallbackMatchesTheShippedConfig(): void
    {
        $shipped = require __DIR__ . '/../../../config/auditing.php';
        $checked = 0;

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../../src'));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                "/Config::get\\('auditing\\.([\\w.]+)', ('[^']*'|true|false|\\d+|\\[[^\\]]*\\])\\)/",
                file_get_contents($file->getPathname()),
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as [, $key, $literal]) {
                $fallback = eval("return {$literal};");

                $this->assertSame(
                    Arr::get($shipped, $key),
                    $fallback,
                    "The fallback for auditing.{$key} in {$file->getFilename()} differs from config/auditing.php",
                );
                ++$checked;
            }
        }

        $this->assertGreaterThan(0, $checked);
    }

    public function testAPublishedUserConfigWithoutAMorphPrefixStillWritesUserColumns(): void
    {
        Config::set('auditing.user', Arr::except(Config::get('auditing.user'), 'morph_prefix'));

        $user = User::factory()->create();
        $article = Article::factory()->create();

        Auditor::actingAs($user, fn () => $article->update(['title' => 'Prefixed']));

        $audit = $article->audits()->latest('id')->first();

        $this->assertSame($user->getKey(), $audit->user_id);
        $this->assertSame($user->getMorphClass(), $audit->user_type);
    }
}
