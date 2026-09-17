<?php

namespace Packstub\Agents\Exceptions;

use LogicException;

/**
 * A chat action that needs the chat idle — compress — was called while a
 * turn runs or a decision waits for the other proposal. A surface offers
 * the action only while AgentChat::idle() is true, so reaching this is a
 * mistake in the caller, not something the person did.
 */
class ChatBusy extends LogicException {}
