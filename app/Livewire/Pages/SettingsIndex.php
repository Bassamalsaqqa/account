<?php

namespace App\Livewire\Pages;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SettingsIndex extends Component
{
    public string $search = '';

    public function render()
    {
        return view('livewire.pages.settings-index');
    }
}
