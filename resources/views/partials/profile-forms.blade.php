{{-- Shared profile + password forms (expects saveProfile / savePassword actions). --}}
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card mb-0 h-100">
            <div class="card-header"><h6 class="card-title mb-0"><i class="ri-user-line me-1"></i>Profile</h6></div>
            <div class="card-body">
                <form wire:submit="saveProfile" class="vstack gap-3">
                    <div>
                        <label class="form-label">Name</label>
                        <input wire:model="name" class="form-control @error('name') is-invalid @enderror">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @isset($emailEditable)
                        <div>
                            <label class="form-label">Email</label>
                            <input wire:model="email" type="email" class="form-control @error('email') is-invalid @enderror" @disabled(! $emailEditable)>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @else
                        <div>
                            <label class="form-label">Email</label>
                            <input wire:model="email" type="email" class="form-control @error('email') is-invalid @enderror">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endisset
                    @isset($extraProfileFields){!! $extraProfileFields !!}@endisset
                    <div><button class="btn btn-primary" type="submit"><i class="ri-save-line me-1"></i>Save profile</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card mb-0 h-100">
            <div class="card-header"><h6 class="card-title mb-0"><i class="ri-lock-password-line me-1"></i>Change password</h6></div>
            <div class="card-body">
                <form wire:submit="savePassword" class="vstack gap-3">
                    <div>
                        <label class="form-label">Current password</label>
                        <input wire:model="current_password" type="password" class="form-control @error('current_password') is-invalid @enderror" autocomplete="current-password">
                        @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label class="form-label">New password</label>
                        <input wire:model="password" type="password" class="form-control @error('password') is-invalid @enderror" autocomplete="new-password">
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label class="form-label">Confirm new password</label>
                        <input wire:model="password_confirmation" type="password" class="form-control" autocomplete="new-password">
                    </div>
                    <div><button class="btn btn-primary" type="submit"><i class="ri-key-2-line me-1"></i>Update password</button></div>
                </form>
            </div>
        </div>
    </div>
</div>
