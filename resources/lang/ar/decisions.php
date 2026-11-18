<?php

// What a short reply typed over a pending proposal decides (AgentTurns::decisionInText()). Lowercase, without
// punctuation: a reply is compared the same way. `yes`: a reply made of nothing but these phrases approves every
// pending proposal, so keep each one a plain yes. `no`: a reply that is one of these rejects them. `no_openers`: a
// reply that opens with one of these words rejects them, whatever follows. Every locale's lists apply, whatever the
// app's locale. A file under lang/vendor/packstub-agents/<locale>/decisions.php adds a language, or replaces a shipped
// one's lists key by key (a key it leaves out keeps the list here).

return [
    'yes' => ['نعم', 'أجل', 'اجل', 'نعم من فضلك', 'نعم تفضل', 'تفضل', 'موافق', 'أوافق', 'اوافق', 'أؤكد', 'اؤكد', 'أكد', 'تمام', 'حسنا', 'حسناً', 'طيب', 'نفذ', 'نفّذ'],
    'no' => ['لا', 'لا شكرا', 'لا شكراً', 'لا داعي', 'لا تفعل', 'إلغاء', 'الغاء', 'ألغ', 'ارفض', 'أرفض', 'أفضل ألا'],
    'no_openers' => ['لا'],
];
