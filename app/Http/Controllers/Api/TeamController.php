<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Mail\TeamInvitationMail;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use App\Services\ActivityLogService;

class TeamController extends Controller
{
    /**
     * List all team members for the current company.
     */
    public function index(Request $request)
    {
        $company = $request->user()->currentCompany;

        $members = $company->users()
            ->select('users.id', 'users.name', 'users.email', 'users.is_active', 'users.created_at')
            ->get()
            ->map(function ($user) use ($request, $company) {
                // Determine role from pivot table
                $role = $user->pivot->role ?? 'Agent';
                
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $role,
                    'joined_at' => $user->pivot?->joined_at ?? $user->pivot?->created_at,
                    'is_active' => $user->is_active,
                    'is_current_user' => $user->id === $request->user()->id,
                ];
            });

        return response()->json([
            'members' => $members,
        ]);
    }

    /**
     * List all pending invitations for the current company.
     */
    public function invitations(Request $request)
    {
        $company = $request->user()->currentCompany;

        $invitations = $company->teamInvitations()
            ->pending()
            ->with('invitedBy:id,name')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($invitation) {
                return [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role,
                    'invited_by' => $invitation->invitedBy->name ?? 'Unknown',
                    'expires_at' => $invitation->expires_at,
                    'created_at' => $invitation->created_at,
                ];
            });

        return response()->json([
            'invitations' => $invitations,
        ]);
    }

    /**
     * Invite a new team member.
     */
    public function invite(Request $request)
    {
        $company = $request->user()->currentCompany;

        // Get allowed roles: default roles (except Company Owner, Super Admin) + custom roles for this company
        $allowedRoles = $this->getAllowedRolesForInvite($company->id);

        $validated = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
            ],
            'role' => [
                'required',
                'string',
                Rule::in($allowedRoles),
            ],
        ]);

        // Check if user is already a member
        $existingMember = $company->users()->where('email', $validated['email'])->first();
        if ($existingMember) {
            return response()->json([
                'message' => 'This email is already a member of your team.',
            ], 422);
        }

        // Check if there's already a pending invitation
        $existingInvitation = $company->teamInvitations()
            ->pending()
            ->where('email', $validated['email'])
            ->first();

        if ($existingInvitation) {
            return response()->json([
                'message' => 'An invitation has already been sent to this email.',
            ], 422);
        }

        // Create invitation
        $invitation = TeamInvitation::create([
            'company_id' => $company->id,
            'email' => $validated['email'],
            'role' => $validated['role'],
            'token' => Str::random(64),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        ActivityLogService::teamMemberInvited($validated['email'], $validated['role']);

        // Send invitation email
        try {
            $mail = new TeamInvitationMail($invitation);
            Mail::to($invitation->email)->send($mail);

            // Log email content for debugging
            Log::channel('mail')->info('Team invitation email sent', [
                'email' => $invitation->email,
                'company' => $company->name,
                'role' => $invitation->role,
                'subject' => "You've been invited to join {$company->name} on ChatHero",
                'inviter' => $invitation->invitedBy->name ?? 'A team member',
                'accept_url' => url("/accept-invitation/{$invitation->token}"),
                'expires_at' => $invitation->expires_at->format('F j, Y'),
                'template' => 'emails.team-invitation',
            ]);
        } catch (\Exception $e) {
            Log::channel('mail')->error('Failed to send team invitation email', [
                'email' => $invitation->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Don't fail the invitation creation, just log the error
        }

        return response()->json([
            'message' => 'Invitation sent successfully.',
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at,
            ],
        ], 201);
    }

    /**
     * Resend an invitation.
     */
    public function resendInvitation(Request $request, $id)
    {
        $company = $request->user()->currentCompany;

        $invitation = $company->teamInvitations()->findOrFail($id);

        // Regenerate token and extend expiration
        $invitation->update([
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        // Resend invitation email
        try {
            // Reload invitation with relationships
            $invitation->load(['invitedBy', 'company']);
            $mail = new TeamInvitationMail($invitation);
            Mail::to($invitation->email)->send($mail);

            // Log email content for debugging
            Log::channel('mail')->info('Team invitation email resent', [
                'email' => $invitation->email,
                'company' => $company->name,
                'role' => $invitation->role,
                'subject' => "You've been invited to join {$company->name} on ChatHero",
                'inviter' => $invitation->invitedBy->name ?? 'A team member',
                'accept_url' => url("/accept-invitation/{$invitation->token}"),
                'expires_at' => $invitation->expires_at->format('F j, Y'),
                'template' => 'emails.team-invitation',
            ]);
        } catch (\Exception $e) {
            Log::channel('mail')->error('Failed to resend team invitation email', [
                'email' => $invitation->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response()->json([
            'message' => 'Invitation resent successfully.',
        ]);
    }

    /**
     * Cancel/delete an invitation.
     */
    public function cancelInvitation(Request $request, $id)
    {
        $company = $request->user()->currentCompany;

        $invitation = $company->teamInvitations()->findOrFail($id);
        $invitation->delete();

        return response()->json([
            'message' => 'Invitation cancelled successfully.',
        ]);
    }

    /**
     * Update a team member's role.
     */
    public function updateRole(Request $request, $memberId)
    {
        $company = $request->user()->currentCompany;
        // Include all roles for update (including Company Owner - authorization checked below)
        $allowedRoles = array_merge(
            ['Company Owner'],
            $this->getAllowedRolesForInvite($company->id)
        );

        $validated = $request->validate([
            'role' => [
                'required',
                'string',
                Rule::in($allowedRoles),
            ],
        ]);
        $currentUser = $request->user();

        // Find the member
        $member = $company->users()->where('users.id', $memberId)->first();

        if (!$member) {
            return response()->json([
                'message' => 'Team member not found.',
            ], 404);
        }

        // Prevent self role change to prevent lockout
        if ($member->id === $currentUser->id) {
            return response()->json([
                'message' => 'You cannot change your own role.',
            ], 422);
        }

        // Get current user's role in this company
        $currentUserRole = $company->users()
            ->where('users.id', $currentUser->id)
            ->first()
            ?->pivot
            ?->role ?? 'Agent';

        // Only Company Owner can assign Company Owner role
        if ($validated['role'] === 'Company Owner' && $currentUserRole !== 'Company Owner') {
            return response()->json([
                'message' => 'Only Company Owners can assign the Company Owner role.',
            ], 403);
        }

        // Update the pivot table
        $company->users()->updateExistingPivot($memberId, [
            'role' => $validated['role'],
        ]);

        // Also update the Spatie role
        $member->syncRoles([$validated['role']]);

        return response()->json([
            'message' => 'Role updated successfully.',
        ]);
    }

    /**
     * Remove a team member.
     */
    public function removeMember(Request $request, $memberId)
    {
        $company = $request->user()->currentCompany;
        $currentUser = $request->user();

        // Find the member
        $member = $company->users()->where('users.id', $memberId)->first();

        if (!$member) {
            return response()->json([
                'message' => 'Team member not found.',
            ], 404);
        }

        // Prevent self-removal
        if ($member->id === $currentUser->id) {
            return response()->json([
                'message' => 'You cannot remove yourself from the team.',
            ], 422);
        }

        // Prevent removing company owner unless you're also an owner
        $memberRole = $member->pivot->role ?? 'Agent';
        $currentUserRole = $company->users()
            ->where('users.id', $currentUser->id)
            ->first()
            ?->pivot
            ?->role ?? 'Agent';

        if ($memberRole === 'Company Owner' && $currentUserRole !== 'Company Owner') {
            return response()->json([
                'message' => 'Only Company Owners can remove other Company Owners.',
            ], 403);
        }

        // Detach from company
        $company->users()->detach($memberId);

        // If this was their current company, reset it
        if ($member->current_company_id === $company->id) {
            $newCompany = $member->companies()->first();
            $member->update([
                'current_company_id' => $newCompany?->id,
            ]);
        }

        ActivityLogService::teamMemberRemoved($member);

        return response()->json([
            'message' => 'Team member removed successfully.',
        ]);
    }

    /**
     * Accept an invitation (public route, no auth required).
     */
    public function acceptInvitation(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string|size:64',
            'name' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        $invitation = TeamInvitation::where('token', $validated['token'])
            ->pending()
            ->first();

        if (!$invitation) {
            return response()->json([
                'message' => 'Invalid or expired invitation.',
            ], 404);
        }

        // Check if user already exists
        $user = User::where('email', $invitation->email)->first();

        // Require name and password for new users
        if (!$user) {
            $request->validate([
                'name' => 'required|string|max:255',
                'password' => 'required|string|min:8|confirmed',
            ]);
        }

        try {
            return DB::transaction(function () use ($invitation, $validated, $user) {
                if ($user) {
                    // Existing user - just add to company
                    $user->companies()->attach($invitation->company_id, [
                        'role' => $invitation->role,
                        'joined_at' => now(),
                    ]);

                    // Assign Spatie role for existing users too
                    if (\Spatie\Permission\Models\Role::where('name', $invitation->role)->where('guard_name', 'web')->exists()) {
                        $user->assignRole($invitation->role);
                    }

                    // If they don't have a current company, set this one
                    if (!$user->current_company_id) {
                        $user->update(['current_company_id' => $invitation->company_id]);
                    }
                } else {
                    // New user - create account
                    $user = User::create([
                        'name' => $validated['name'],
                        'email' => $invitation->email,
                        'password' => Hash::make($validated['password']),
                        'current_company_id' => $invitation->company_id,
                        'is_active' => true,
                    ]);

                    $user->companies()->attach($invitation->company_id, [
                        'role' => $invitation->role,
                        'joined_at' => now(),
                    ]);

                    // Assign Spatie role
                    if (\Spatie\Permission\Models\Role::where('name', $invitation->role)->where('guard_name', 'web')->exists()) {
                        $user->assignRole($invitation->role);
                    }
                }

                // Mark invitation as accepted
                $invitation->markAsAccepted();

                // Generate token
                $token = $user->createToken('auth-token')->plainTextToken;

                return response()->json([
                    'message' => 'Invitation accepted successfully.',
                    'user' => new UserResource($user->load('currentCompany')),
                    'token' => $token,
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Failed to accept team invitation', [
                'token' => $validated['token'],
                'email' => $invitation->email ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to accept invitation. Please try again.',
                'debug' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get invitation details by token (for the accept invitation page).
     */
    public function getInvitationByToken(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string|size:64',
        ]);

        $invitation = TeamInvitation::where('token', $validated['token'])
            ->with(['company:id,name', 'invitedBy:id,name'])
            ->first();

        if (!$invitation) {
            return response()->json([
                'message' => 'Invitation not found.',
            ], 404);
        }

        if ($invitation->accepted_at) {
            return response()->json([
                'message' => 'This invitation has already been used.',
            ], 410);
        }

        if ($invitation->isExpired()) {
            return response()->json([
                'message' => 'This invitation has expired.',
            ], 410);
        }

        // Check if user already exists
        $existingUser = User::where('email', $invitation->email)->exists();

        return response()->json([
            'invitation' => [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'company_name' => $invitation->company->name,
                'invited_by' => $invitation->invitedBy?->name ?? 'A team member',
                'expires_at' => $invitation->expires_at,
                'existing_user' => $existingUser,
            ],
        ]);
    }

    /**
     * Get available roles for the current user to assign.
     */
    public function availableRoles(Request $request)
    {
        $currentUser = $request->user();
        $company = $currentUser->currentCompany;

        if (!$company) {
            return response()->json([
                'message' => 'No company associated with this user.',
            ], 404);
        }

        // Determine current user role from pivot table
        $currentUserRole = $company->users()
            ->where('users.id', $currentUser->id)
            ->first()
            ?->pivot
            ?->role ?? 'Agent';

        // Get all available roles (defaults + custom for this company)
        $allRoles = $this->getAllowedRolesForInvite($company->id);

        // Company Owners can assign all roles including Company Owner
        // Company Admins can only assign Agent and custom roles (but not Company Owner/Admin)
        $roles = match ($currentUserRole) {
            'Company Owner' => array_merge(['Company Owner'], $allRoles),
            'Company Admin' => array_filter($allRoles, fn($r) => !in_array($r, ['Company Owner', 'Company Admin'])),
            default => [],
        };

        // Remove duplicates and re-index
        $roles = array_values(array_unique($roles));

        return response()->json([
            'roles' => $roles,
            'current_user_role' => $currentUserRole,
        ]);
    }

    /**
     * Get all allowed roles for inviting team members.
     * Returns default roles (except Company Owner, Super Admin) + custom roles for the company.
     */
    private function getAllowedRolesForInvite(int $companyId): array
    {
        // Get default roles (except Company Owner and Super Admin)
        $defaultRoles = Role::whereNull('company_id')
            ->whereNotIn('name', ['Company Owner', 'Super Admin'])
            ->pluck('name')
            ->toArray();

        // Get custom roles for this company
        $customRoles = Role::where('company_id', $companyId)
            ->where('is_custom', true)
            ->pluck('name')
            ->toArray();

        return array_merge($defaultRoles, $customRoles);
    }
}
