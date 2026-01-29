export type ApiResponse<T> =
    | { success: true; message: string; data: T }
    | { success: false; message: string; errors?: Record<string, unknown> };
export type LoginRequest = {
    email: string;
    password: string;
};
export type RegisterRequest = {
    name: string;
    email: string;
    password: string;
};
export type UserData = {
    id: number;
    created_at: string;
    updated_at: string;
    name: string;
    email: string;
    role: UserRole;
    email_verified_at?: string;
    password?: string;
};
export type UserResource = {
    id: number;
    name: string;
    role: UserRole;
    email: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
};
export type UserResourceResponse = ApiResponse<UserResource>;
export enum UserRole {
    Admin = "0",
    User = "1",
}
