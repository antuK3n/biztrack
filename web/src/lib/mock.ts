import type { AxiosInstance, InternalAxiosRequestConfig } from 'axios'
import { AxiosError } from 'axios'
import type { User } from './types'

/*
 * Dev-only mock driver (VITE_USE_MOCK_API=true). Implements the sprint 1 §E1
 * auth contract — same envelopes, same status codes, same lockout rules — so
 * the Laravel api/ swaps in by flipping the env var, with zero screen edits.
 * State lives in memory: a page reload resets registrations and lockouts.
 */

export const DEMO_PASSWORD = 'biztrack1'

interface MockUser extends User {
  password: string
  failed_attempts: number
  locked_until: number | null
}

let nextId = 100

function makeUser(partial: Partial<MockUser> & Pick<MockUser, 'email' | 'first_name' | 'last_name' | 'gender'>): MockUser {
  return {
    id: nextId++,
    mobile_number: '09171234567',
    middle_name: null,
    suffix: null,
    department: null,
    is_active: true,
    email_verified_at: '2026-07-01T08:00:00Z',
    roles: ['business_owner'],
    permissions: [
      'business.manage_own',
      'application.create',
      'application.view_own',
      'document.upload_own',
      'payment.make',
      'permit.view_own',
      'request.respond',
      'message.participate',
    ],
    password: DEMO_PASSWORD,
    failed_attempts: 0,
    locked_until: null,
    ...partial,
  }
}

const users: MockUser[] = [
  makeUser({ email: 'owner@biztrack.local', first_name: 'Nena', last_name: 'Dela Cruz', gender: 'F' }),
  makeUser({
    email: 'bplo@biztrack.local',
    first_name: 'Liza',
    last_name: 'Reyes',
    gender: 'F',
    department: { id: 1, code: 'BPLO', name: 'Business Permits and Licensing Office' },
    roles: ['bplo_staff'],
    permissions: ['application.view_all', 'application.review', 'fee.adjust', 'permit.view_all', 'permit.issue', 'request.create', 'message.participate', 'compliance.view', 'zoning.evaluate'],
  }),
  makeUser({
    email: 'admin@biztrack.local',
    first_name: 'Ramon',
    last_name: 'Santos',
    gender: 'M',
    roles: ['admin'],
    permissions: [
      'application.view_all',
      'application.review',
      'fee.adjust',
      'inspection.manage',
      'permit.view_all',
      'permit.issue',
      'request.create',
      'message.participate',
      'compliance.view',
      'analytics.view',
      'zoning.evaluate',
      'user.manage',
      'owner.manage_status',
      'oic.assign',
      'reference.manage',
      'audit.view',
    ],
  }),
  makeUser({
    email: 'sanitary@biztrack.local',
    first_name: 'Carlos',
    last_name: 'Dizon',
    gender: 'M',
    department: { id: 2, code: 'CHO', name: 'City Health Office' },
    roles: ['sanitary_officer'],
    permissions: ['application.view_all', 'application.review', 'inspection.manage', 'permit.view_all', 'request.create', 'message.participate', 'compliance.view'],
  }),
  makeUser({
    email: 'inactive@biztrack.local',
    first_name: 'Mario',
    last_name: 'Santos',
    gender: 'M',
    is_active: false,
  }),
]

const sessions = new Map<string, number>() // token → user id
const resetTokens = new Map<string, string>() // email → token
// Standing demo reset link (state is in-memory, so a fresh page load would
// otherwise lose tokens minted via forgot-password):
// /reset-password?token=demo-reset-token&email=owner@biztrack.local
resetTokens.set('owner@biztrack.local', 'demo-reset-token')

function publicUser(u: MockUser): User {
  const { password: _pw, failed_attempts: _fa, locked_until: _lu, ...rest } = u
  return rest
}

function issueToken(u: MockUser): string {
  const token = `mock-${u.id}-${Math.random().toString(36).slice(2)}`
  sessions.set(token, u.id)
  return token
}

function findByEmail(email: string) {
  return users.find((u) => u.email.toLowerCase() === email.trim().toLowerCase())
}

function authed(config: InternalAxiosRequestConfig): MockUser | undefined {
  const header = String(config.headers?.Authorization ?? '')
  const token = header.replace(/^Bearer\s+/i, '')
  const id = sessions.get(token)
  return users.find((u) => u.id === id)
}

interface MockResponse {
  status: number
  data: unknown
}

function handle(config: InternalAxiosRequestConfig): MockResponse {
  const method = (config.method ?? 'get').toLowerCase()
  const url = (config.url ?? '').replace(/\/+$/, '')
  const body = config.data ? JSON.parse(config.data as string) : {}
  const route = `${method} ${url}`

  switch (route) {
    case 'post /auth/register': {
      if (findByEmail(body.email)) {
        return {
          status: 422,
          data: {
            message: 'The email has already been taken.',
            errors: { email: ['This email is already registered. Try signing in instead.'] },
          },
        }
      }
      const user = makeUser({
        email: body.email.trim(),
        first_name: body.first_name,
        middle_name: body.middle_name || null,
        last_name: body.last_name,
        suffix: body.suffix || null,
        gender: body.gender,
        mobile_number: body.mobile_number,
        password: body.password,
        email_verified_at: null,
      })
      users.push(user)
      return { status: 201, data: { data: { token: issueToken(user), user: publicUser(user) } } }
    }

    case 'post /auth/login': {
      const user = findByEmail(body.email ?? '')
      if (!user) return { status: 422, data: { message: 'Invalid credentials.', errors: {} } }
      if (user.locked_until && user.locked_until > Date.now()) {
        const minutes = Math.max(1, Math.ceil((user.locked_until - Date.now()) / 60000))
        return {
          status: 429,
          data: { message: `Account temporarily locked. Try again in ${minutes} minute${minutes === 1 ? '' : 's'}.`, errors: {} },
        }
      }
      if (user.password !== body.password) {
        user.failed_attempts += 1
        if (user.failed_attempts >= 5) {
          user.locked_until = Date.now() + 15 * 60000
          user.failed_attempts = 0
        }
        return { status: 422, data: { message: 'Invalid credentials.', errors: {} } }
      }
      if (!user.is_active) return { status: 403, data: { message: 'Account is deactivated.', errors: {} } }
      user.failed_attempts = 0
      user.locked_until = null
      return { status: 200, data: { data: { token: issueToken(user), user: publicUser(user) } } }
    }

    case 'post /auth/logout': {
      const header = String(config.headers?.Authorization ?? '')
      sessions.delete(header.replace(/^Bearer\s+/i, ''))
      return { status: 204, data: undefined }
    }

    case 'get /auth/me': {
      const user = authed(config)
      if (!user) return { status: 401, data: { message: 'Unauthenticated.', errors: {} } }
      return { status: 200, data: { data: publicUser(user) } }
    }

    case 'post /auth/forgot-password': {
      const user = findByEmail(body.email ?? '')
      if (user) resetTokens.set(user.email, 'demo-reset-token')
      // Always 200 — no user enumeration.
      return { status: 200, data: { message: 'If that email is registered, a reset link is on its way.' } }
    }

    case 'post /auth/reset-password': {
      const user = findByEmail(body.email ?? '')
      const valid = user && resetTokens.get(user.email) === body.token
      if (!valid) {
        return {
          status: 422,
          data: {
            message: 'This password reset link is invalid or has expired.',
            errors: { email: ['This password reset link is invalid or has expired.'] },
          },
        }
      }
      user.password = body.password
      resetTokens.delete(user.email)
      sessions.forEach((id, token) => {
        if (id === user.id) sessions.delete(token)
      })
      return { status: 200, data: { message: 'Your password has been reset.' } }
    }

    case 'post /auth/email/verify': {
      const user = users.find((u) => u.id === Number(body.id))
      if (!user || body.hash !== 'demo-hash') {
        return { status: 422, data: { message: 'This verification link is invalid or has expired.', errors: {} } }
      }
      user.email_verified_at = new Date().toISOString()
      return { status: 200, data: { message: 'Email verified.' } }
    }

    case 'post /auth/email/resend': {
      const user = authed(config)
      if (!user) return { status: 401, data: { message: 'Unauthenticated.', errors: {} } }
      return { status: 200, data: { message: 'Verification email sent.' } }
    }

    default:
      return { status: 404, data: { message: `Mock route not found: ${route}`, errors: {} } }
  }
}

export function installMockAdapter(instance: AxiosInstance) {
  instance.defaults.adapter = async (config) => {
    // Simulated network latency keeps loading states honest.
    await new Promise((r) => setTimeout(r, 400 + Math.random() * 350))
    const { status, data } = handle(config)
    const response = {
      status,
      statusText: '',
      data,
      headers: {},
      config,
    }
    if (status >= 400) {
      throw new AxiosError(`Request failed with status code ${status}`, String(status), config, undefined, response)
    }
    return response
  }
  // eslint-disable-next-line no-console
  console.info(
    `[BizTrack] Mock API active. Demo sign-in: owner@biztrack.local / ${DEMO_PASSWORD} (staff: bplo@biztrack.local)`,
  )
}
