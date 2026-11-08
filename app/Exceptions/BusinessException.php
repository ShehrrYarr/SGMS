<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A rule violation the user can fix (insufficient stock, balance, etc.).
 * Livewire components catch it and show the message as a toast.
 */
class BusinessException extends RuntimeException {}
