<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    /** Return current user's profile as JSON. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user(); // Mas safe kaysa Auth::user() para sa Eloquent methods
        
        return response()->json([
            'name'       => $user->name,
            'email'      => $user->email,
            'phone'      => $user->phone ?? '',
            'dob'        => $user->dob ? $user->dob->format('Y-m-d') : '',
            'avatar_url' => $user->avatar_url, // Ginamit natin yung accessor mo galing sa User.php!
        ]);
    }

    /** Update name and phone. */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'dob'   => 'nullable|date',
        ]);

        $user = $request->user();
        
        $user->update([
            'name'  => $validated['name'],
            // Kapag empty string ang sinend, gagawin nating literal na null sa database
            'phone' => empty($validated['phone']) ? null : $validated['phone'],
            'dob'   => empty($validated['dob']) ? null : $validated['dob'],
        ]);

        return response()->json(['success' => true, 'name' => $user->name]);
    }

    /** Upload and save profile picture. */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        $user = $request->user();

        // Delete old avatar kapag nag-upload ng bago para di mapuno server natin
        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        // I-save ang file sa storage/app/public/avatars
        $path = $request->file('avatar')->store('avatars', 'public');
        
        $user->update(['avatar' => $path]);

        return response()->json([
            'success'    => true,
            'avatar_url' => $user->avatar_url, // Use accessor again para dynamic
        ]);
    }

    /** Remove profile picture. */
    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        $user->update(['avatar' => null]);

        return response()->json(['success' => true, 'avatar_url' => null]);
    }
}