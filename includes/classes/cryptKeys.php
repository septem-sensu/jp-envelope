<?php

declare( strict_types = 1 );

/**
 * This file carries the placeholder passphrases of the repo.
 * Production overrides it with the gitignored .cryptKeys.php which
 * BaseObject::getCryptKeys() prefers — on deployment .cryptKeys.php must
 * exist, otherwise these placeholders take effect and the persisted
 * data stays unreadable. The array carries the keys passphrase1,
 * passphrase2 and mailAddress, whose placeholder values
 * {{PASSPHRASE1}}, {{PASSPHRASE2}} and {{MAILADDRESS}} have to be
 * replaced with real values outside the repo.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-10-10
 *
 */

return [ 'passphrase1' => '{{PASSPHRASE1}}', 'passphrase2' => '{{PASSPHRASE2}}', 'mailAddress' => '{{MAILADDRESS}}' ];

// EOF
