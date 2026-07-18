<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class HelloWorldWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = -20;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.hello-world-widget';
}
