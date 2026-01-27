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
export enum UserRole {
    Admin = "0",
    User = "1",
}
