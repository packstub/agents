<?php

// What a short reply typed over a pending proposal decides (AgentTurns::decisionInText()). Lowercase, without
// punctuation: a reply is compared the same way. `yes`: a reply made of nothing but these phrases approves every
// pending proposal, so keep each one a plain yes. `no`: a reply that is one of these rejects them. `no_openers`: a
// reply that opens with one of these words rejects them, whatever follows. Publish a file for another language
// under lang/vendor/packstub-agents/<locale>/decisions.php; every locale's lists apply, whatever the app's locale.

return [
    'yes' => ['yes', 'yes please', 'yes go ahead', 'go ahead', 'ok', 'okay', 'sure', 'yep', 'yeah', 'approve', 'approved', 'approve it', 'confirm', 'confirmed', 'confirm it', 'do it', 'please do', 'proceed', 'go for it', 'sounds good', 'yes do it', 'yes confirm', 'yes confirm it', 'yes approve', 'yes please go ahead'],
    'no' => ['no', 'nope', 'no thanks', 'no thank you', 'reject', 'rejected', 'reject it', 'cancel', 'stop', 'never mind', 'do not', 'dont', 'don t', 'leave it', 'not now', 'no do not'],
    'no_openers' => ['no', 'nope', 'cancel', 'reject', 'stop', 'never'],
];
