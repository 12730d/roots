<?php

/**
 * CSRF Protection System - Legacy Wrapper
 *
 * This file provides backward compatibility for old code using procedural functions.
 * All calls are forwarded to the new namespaced CsrfProtection class.
 *
 * @deprecated Use ROOTS\Security\CsrfProtection class instead
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ROOTS\Security\CsrfProtection;

/**
 * @deprecated Use CsrfProtection::generateToken() instead
 * @return string
 */
function generateCsrfToken(): string
{
    return CsrfProtection::generateToken();
}

/**
 * @deprecated Use CsrfProtection::getToken() instead
 * @return string|null
 */
function getCsrfToken(): ?string
{
    return CsrfProtection::getToken();
}

/**
 * @deprecated Use CsrfProtection::verifyToken() instead
 * @param string|null $token
 * @return bool
 */
function verifyCsrfToken(?string $token = null): bool
{
    return CsrfProtection::verifyToken($token);
}

/**
 * @deprecated Use CsrfProtection::requireToken() instead
 * @return void
 */
function requireCsrfToken(): void
{
    CsrfProtection::requireToken();
}

/**
 * @deprecated Use CsrfProtection::tokenField() instead
 * @return string
 */
function csrfTokenField(): string
{
    return CsrfProtection::tokenField();
}

/**
 * @deprecated Use CsrfProtection::tokenMeta() instead
 * @return string
 */
function csrfTokenMeta(): string
{
    return CsrfProtection::tokenMeta();
}
