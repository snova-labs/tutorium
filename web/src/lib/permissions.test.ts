import { describe, expect, it } from "vitest";

import { can } from "@/lib/permissions";

describe("can", () => {
  it("treats the Owner as holding every permission, as the API does", () => {
    expect(can({ roles: ["Owner"], permissions: [] }, "billing.manage")).toBe(true);
  });

  it("otherwise goes by the permissions the API reports", () => {
    const teacher = { roles: ["Teacher"], permissions: ["attendance.record"] };

    expect(can(teacher, "attendance.record")).toBe(true);
    expect(can(teacher, "billing.manage")).toBe(false);
  });
});
