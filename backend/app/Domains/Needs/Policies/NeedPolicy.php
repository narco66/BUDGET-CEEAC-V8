<?php

namespace App\Domains\Needs\Policies;

use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Needs\Services\ExpressionBesoinWorkflow;
use App\Models\User;

class NeedPolicy
{
    public function __construct(private readonly ExpressionBesoinWorkflow $workflow) {}

    public function viewAny(User $user): bool
    {
        return $user->holdsAny();
    }

    public function view(User $user, ExpressionBesoin $eb): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->porte('eb.creer');
    }

    public function update(User $user, ExpressionBesoin $eb): bool
    {
        return $eb->isEditable() && $user->id === $eb->initiator_id;
    }

    public function submit(User $user, ExpressionBesoin $eb): bool
    {
        return $this->update($user, $eb);
    }

    public function upload(User $user, ExpressionBesoin $eb): bool
    {
        return $this->update($user, $eb);
    }

    public function validateStep(User $user, ExpressionBesoin $eb): bool
    {
        return $this->workflow->allows($user, $eb) && $eb->workflow_step !== 'ordonnateur';
    }

    public function approve(User $user, ExpressionBesoin $eb): bool
    {
        return $this->workflow->allows($user, $eb) && $eb->workflow_step === 'ordonnateur';
    }

    public function sendBack(User $user, ExpressionBesoin $eb): bool
    {
        return $this->workflow->allows($user, $eb);
    }

    public function reject(User $user, ExpressionBesoin $eb): bool
    {
        return $this->workflow->allows($user, $eb);
    }

    public function transform(User $user, ExpressionBesoin $eb): bool
    {
        return $eb->status->value === 'approuvee';
    }

    public function cancel(User $user, ExpressionBesoin $eb): bool
    {
        return $this->update($user, $eb) || $user->holds('ordonnateur');
    }

    public function duplicate(User $user, ExpressionBesoin $eb): bool
    {
        return $this->view($user, $eb);
    }

    public function pdf(User $user, ExpressionBesoin $eb): bool
    {
        return $this->view($user, $eb);
    }
}
