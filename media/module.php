<?php

declare(strict_types=1);

/**
 * Compatibility shim for repositories that still contain the old root /media
 * directory from v0.2.0. IP-Symcon requires every non-exempt root directory
 * to be a valid module. This module is never instantiated by SamsungAlarmvideoTest.
 */
class SamsungAlarmvideoLegacyMedia extends IPSModuleStrict
{
}
