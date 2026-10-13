import { randomUUID } from 'node:crypto';

import type { APIRequestContext, APIResponse } from '@playwright/test';

import { expect } from './fixtures';
import { demoPassword, userFor } from './demo';

/**
 * Mobile API v1 client for the specs that used to drive technician or customer web pages. Those accounts
 * are app-only on the web, so their server-side behaviour is exercised the way the apps do it:
 * a Sanctum bearer token from `POST /api/v1/auth/login`, JSON bodies, and a fresh Idempotency-Key on every
 * mutation (docs/SECURITY.md section 4).
 */
export type ApiApp = 'technician' | 'customer';

export class ApiClient {
    constructor(
        private readonly request: APIRequestContext,
        private readonly token: string,
    ) {}

    private headers(mutation: boolean): Record<string, string> {
        return {
            Accept: 'application/json',
            Authorization: `Bearer ${this.token}`,
            ...(mutation ? { 'Idempotency-Key': randomUUID() } : {}),
        };
    }

    get(path: string): Promise<APIResponse> {
        return this.request.get(`/api/v1/${path}`, { headers: this.headers(false) });
    }

    post(path: string, data?: Record<string, unknown>): Promise<APIResponse> {
        return this.request.post(`/api/v1/${path}`, { headers: this.headers(true), data });
    }

    postFile(
        path: string,
        file: { name: string; mimeType: string; buffer: Buffer },
        fields: Record<string, string> = {},
    ): Promise<APIResponse> {
        return this.request.post(`/api/v1/${path}`, {
            headers: this.headers(true),
            multipart: { ...fields, file },
        });
    }

    delete(path: string): Promise<APIResponse> {
        return this.request.delete(`/api/v1/${path}`, { headers: this.headers(true) });
    }
}

/** Signs a demo technician or customer into its app and returns a client holding the token. */
export async function apiLogin(request: APIRequestContext, app: ApiApp): Promise<ApiClient> {
    const response = await request.post('/api/v1/auth/login', {
        headers: { Accept: 'application/json' },
        data: {
            email: userFor(app).email,
            password: demoPassword,
            device_name: 'e2e',
            app,
        },
    });
    expect(response.status(), 'POST /api/v1/auth/login').toBe(200);

    const body = (await response.json()) as { data: { token: string } };

    return new ApiClient(request, body.data.token);
}
