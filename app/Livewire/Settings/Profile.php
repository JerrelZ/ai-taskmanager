<?php

namespace App\Livewire\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Services\ImageProcessor;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Title('Profile settings')]
class Profile extends Component
{
    use ProfileValidationRules, WithFileUploads;

    public string $name = '';

    public string $email = '';

    /**
     * Newly selected profile picture awaiting save.
     */
    public ?TemporaryUploadedFile $avatar = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(ImageProcessor $images): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id) + [
            'avatar' => ['nullable', 'image', 'max:5120'],
        ]);

        $user->fill(collect($validated)->except('avatar')->all());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if ($this->avatar !== null) {
            $this->storeAvatar($user, $images);
        }

        $user->save();

        $this->avatar = null;

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Remove the user's current profile picture.
     */
    public function removeAvatar(): void
    {
        $user = Auth::user();

        if ($user->avatar_path !== null) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->avatar_path = null;
            $user->save();
        }

        $this->avatar = null;

        Flux::toast(variant: 'success', text: __('Profile picture removed.'));
    }

    /**
     * Resize and persist the pending upload to the public disk, replacing any
     * existing avatar.
     */
    private function storeAvatar(User $user, ImageProcessor $images): void
    {
        $file = $this->avatar;
        $directory = 'avatars/'.$user->id;
        $realPath = $file->getRealPath();

        $processed = $realPath !== false
            ? $images->process($realPath, $file->getClientOriginalName())
            : null;

        if ($processed !== null) {
            $extension = pathinfo($processed['filename'], PATHINFO_EXTENSION);
            $path = $directory.'/'.Str::uuid().'.'.$extension;
            Storage::disk('public')->put($path, $processed['contents']);
        } else {
            $path = $file->store($directory, 'public');
        }

        $previous = $user->avatar_path;
        $user->avatar_path = $path;

        if ($previous !== null) {
            Storage::disk('public')->delete($previous);
        }
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Flux::toast(text: __('A new verification link has been sent to your email address.'));
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        $user = Auth::user();

        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        $user = Auth::user();

        return ! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail();
    }
}
