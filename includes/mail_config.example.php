<?php
define('BREVO_API_KEY',   'CHANGE_ME');   // starts with xkeysib-
define('MAIL_FROM_EMAIL', 'CHANGE_ME'); // the address you verified in Brevo
define('MAIL_FROM_NAME',  'CHANGE_ME');
// ── AI (Groq free tier) ──
define('GROQ_API_KEY', 'CHANGE_ME');
define('GROQ_MODEL',   'CHANGE_ME');
define('AI_DAILY_CAP', 500);
// ── AI fallback providers (tried in this order after Groq) ──
define('GEMINI_API_KEY',     'CHANGE_ME');
define('GEMINI_MODEL',       'CHANGE_ME');
define('OPENROUTER_API_KEY', 'CHANGE_ME');
define('OPENROUTER_MODEL',   'CHANGE_ME');                        // max AI calls per client per day