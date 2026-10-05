import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api-error";

const api = vi.fn();
const setToken = vi.fn();
const redirect = vi.fn((url: string) => {
  throw new Error(`redirect:${url}`);
});

vi.mock("@/lib/api", () => ({ api: (...args: unknown[]) => api(...args) }));
vi.mock("@/lib/session", () => ({ setToken: (token: string) => setToken(token) }));
vi.mock("next/navigation", () => ({ redirect: (url: string) => redirect(url) }));

const { signIn } = await import("@/app/sign-in/actions");

function form(fields: Record<string, string>): FormData {
  const data = new FormData();
  Object.entries(fields).forEach(([k, v]) => data.set(k, v));
  return data;
}

describe("signIn", () => {
  beforeEach(() => {
    api.mockReset();
    setToken.mockReset();
  });

  it("stores the token and goes home when no code is needed", async () => {
    api.mockResolvedValue({ data: { token: "1|abc" } });

    await expect(
      signIn({ step: "credentials", email: "" }, form({ email: "t@sample.test", password: "pw" })),
    ).rejects.toThrow("redirect:/");

    expect(setToken).toHaveBeenCalledWith("1|abc");
    expect(api).toHaveBeenCalledWith("auth/login", expect.objectContaining({ anonymous: true }));
  });

  it("moves to the code step when the API emails a code", async () => {
    api.mockRejectedValue(
      new ApiError(422, "Code", { code: ["We have emailed you a sign-in code. Enter it to finish signing in."] }),
    );

    const state = await signIn({ step: "credentials", email: "" }, form({ email: "o@sample.test", password: "pw" }));

    expect(state).toEqual({
      step: "code",
      email: "o@sample.test",
      notice: "We have emailed you a sign-in code. Enter it to finish signing in.",
    });
    expect(setToken).not.toHaveBeenCalled();
  });

  it("sends the code with the same details, and stays on the code step if it is wrong", async () => {
    api.mockRejectedValue(new ApiError(422, "Bad", { code: ["That code is not right, or it has expired."] }));

    const state = await signIn(
      { step: "code", email: "o@sample.test" },
      form({ email: "o@sample.test", password: "pw", code: "123456" }),
    );

    expect(api).toHaveBeenCalledWith(
      "auth/login",
      expect.objectContaining({ json: expect.objectContaining({ code: "123456", password: "pw" }) }),
    );
    expect(state.step).toBe("code");
    expect(state.error).toBe("That code is not right, or it has expired.");
  });

  it("shows the API's single message for wrong details, without saying which part", async () => {
    api.mockRejectedValue(new ApiError(422, "x", { email: ["Those details do not match an active account."] }));

    const state = await signIn({ step: "credentials", email: "" }, form({ email: "x@sample.test", password: "no" }));

    expect(state).toMatchObject({ step: "credentials", error: "Those details do not match an active account." });
  });
});
