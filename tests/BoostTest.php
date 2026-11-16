<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('ships Boost guidelines that render through Blade unchanged', function () {
    $source = file_get_contents(__DIR__.'/../resources/boost/guidelines/core.blade.php');

    expect(Blade::render($source))->toBe($source);
});

it('ships an upgrade skill with valid front matter and existing references', function () {
    $dir = __DIR__.'/../resources/boost/skills/zhylon-api-resources-upgrade';
    $skill = file_get_contents($dir.'/SKILL.md');

    expect($skill)->toStartWith("---\nname: zhylon-api-resources-upgrade\ndescription: ");

    preg_match_all('/\]\((references\/[^)]+)\)/', $skill, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $reference) {
        expect($dir.'/'.$reference)->toBeFile();
    }
});
