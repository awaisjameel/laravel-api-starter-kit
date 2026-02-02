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
    password_confirmation: string;
};
export type StoreUserRequest = {
    name: string;
    email: string;
    role: UserRole;
    password: string;
    password_confirmation: string;
};
export type UpdateUserRequest = {
    name?: string;
    email?: string;
    role?: UserRole;
    password?: string | null;
    password_confirmation?: string | null;
};
export type UserCollection =
    | Array<UserResource>
    | {
          data: Array<UserResource>;
          links: {
              first?: string;
              last?: string;
              prev?: string | null;
              next?: string | null;
          };
          meta: {
              current_page: number;
              from?: number | null;
              last_page: number;
              path: string;
              per_page: number;
              to?: number | null;
              total: number;
          };
      };
export type UserCollectionResponse = ApiResponse<UserCollection>;
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
    created_at: string | null;
    updated_at: string | null;
};
export type UserResourceResponse = ApiResponse<UserResource>;
export enum UserRole {
    Admin = 0,
    User = 1,
}
