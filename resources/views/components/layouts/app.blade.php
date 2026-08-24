{{-- Thin wrapper so non-Livewire pages can use the same shell. --}}
@include('layouts.app', ['slot' => $slot, 'title' => $title ?? null])
