<?php

namespace App\Livewire\Storefront;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * The filter sidebar on a desktop and the bottom sheet on a phone: one panel, restyled by breakpoint. It draws
 * what `ResultGrid` hands it and sends every choice back to it through `$parent`, so it keeps no state.
 */
class FilterPanel extends Component
{
    /**
     * @var array<string, mixed>
     */
    #[Reactive]
    public array $panel = [];

    public function render(): View
    {
        return view('livewire.storefront.filter-panel');
    }
}
