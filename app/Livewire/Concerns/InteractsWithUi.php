<?php

namespace App\Livewire\Concerns;

use App\Exceptions\BusinessException;
use Closure;

/**
 * Toasts, modals and business-error handling for Volt components.
 */
trait InteractsWithUi
{
    public function toast(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', type: $type, message: $message);
    }

    public function openModal(string $name): void
    {
        $this->dispatch('open-modal', name: $name);
    }

    public function closeModal(string $name): void
    {
        $this->dispatch('close-modal', name: $name);
    }

    /**
     * Run an action; turn BusinessException into an error toast.
     * Returns the callback result, or null on failure.
     */
    protected function attempt(Closure $callback, ?string $success = null): mixed
    {
        try {
            $result = $callback();

            if ($success) {
                $this->toast($success);
            }

            return $result ?? true;
        } catch (BusinessException $e) {
            $this->toast($e->getMessage(), 'error');

            return null;
        }
    }
}
