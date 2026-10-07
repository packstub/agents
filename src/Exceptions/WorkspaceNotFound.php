<?php

namespace Packstub\Agents\Exceptions;

/**
 * A workspace key or slug that matches no row of the model Agents::tenantModel()
 * named: deleted between the question and the worker, or an unknown slug in a
 * mail's tenant field. A WorkspaceAccessDenied, so every path refuses it the
 * same way — nothing runs without the workspace it was asked in.
 */
class WorkspaceNotFound extends WorkspaceAccessDenied
{
    public static function make(): static
    {
        return new static(__('This workspace no longer exists.'));
    }
}
