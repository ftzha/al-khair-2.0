
// Roles supported by the Laravel backend.
export type UserRole = 'MEMBER' | 'COMMITTEE' | 'ADMIN'

// The user information actually returned by POST /api/login.
// Keep this interface aligned with the backend response.
export interface AuthUser {
    id: number
    full_name: string
    email: string
    role: UserRole
    email_verified: boolean
}

// Login credentials sent to Laravel.
export interface LoginPayload {
    email: string
    password: string
}

// Laravel wraps successful responses in a "data" object.
export interface LoginResponse {
    message: string
    data: {
        token: string
        token_type: 'Bearer'
        user: AuthUser
    }
}

// Response returned by GET /api/me.
export interface MeResponse {
    data: AuthUser
}

// Data submitted to POST /api/register.
// The role and account status are assigned by Laravel, not the frontend.
export interface RegisterPayload {
    full_name: string
    identity_number: string
    email: string
    password: string
    password_confirmation: string
}

// Laravel returns field-specific validation errors with HTTP 422.
export interface ValidationErrorResponse {
    message: string
    errors?: Record<string, string[]>
}

// Response returned after requesting another verification email.
export interface VerificationNotificationResponse {
    message: string
}

// Frontend access checks improve navigation; Laravel remains authoritative.
export function hasCommitteeAccess(role: UserRole | null | undefined): boolean {
    return role === 'COMMITTEE' || role === 'ADMIN'
}
