<?php

namespace App\Livewire\Concerns;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\User;
use App\Support\Demo;
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
     * Public demo: refuse a change that would lock the next visitor out.
     * Returns true (and shows why) when the action must stop.
     */
    protected function blockedInDemo(User|Customer|null $account, string $what): bool
    {
        if (! Demo::protects($account)) {
            return false;
        }

        $this->toast($what.' is turned off in the demo, so the next visitor can still sign in.', 'error');

        return true;
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
