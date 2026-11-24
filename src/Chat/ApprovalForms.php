<?php

namespace Packstub\Agents\Chat;

/**
 * What a host implements, beside ChatHost, to draw a waiting proposal's decision itself: a form that corrects the
 * call's arguments before approving, for one. The view replaces the Approve and Reject buttons and sits under the
 * question and its preview; it reads $tool (the proposal as AgentChat::messages() lists it) and $message. A host
 * without it, or a view of null for a proposal, keeps the buttons. Filament Agents implements it for tools with an
 * approval form; whatever approves with corrected arguments calls AgentChat::decide() with them, after validating.
 */
interface ApprovalForms
{
    /**
     * The view that draws this waiting proposal's decision, or null for the Approve and Reject buttons.
     *
     * @param  array<string, mixed>  $tool
     */
    public function approvalView(array $tool): ?string;
}
