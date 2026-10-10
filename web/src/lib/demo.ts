export type DemoState = "none" | "building" | "ready" | "removing" | "failed";

export interface DemoAccount {
  name: string;
  email: string;
  role: string;
}

export interface DemoAcademy {
  academy: string;
  kind: string;
  accounts: DemoAccount[];
}

export interface DemoStatus {
  state: DemoState;
  message: string | null;
  updated_at: string | null;
  academies: DemoAcademy[];
}

/** Whether a run is under way, so the page keeps checking and the buttons wait. */
export function isBusy(state: DemoState): boolean {
  return state === "building" || state === "removing";
}

/** One line on where the demo stands. */
export function stateLabel(state: DemoState): string {
  switch (state) {
    case "none":
      return "No demo data is loaded.";
    case "building":
      return "Loading the demo academies…";
    case "ready":
      return "The demo academies are ready.";
    case "removing":
      return "Removing the demo academies…";
    case "failed":
      return "The last run did not finish.";
  }
}

/** Roles that confirm each sign-in with an emailed code, so the page can say so beside them. */
export function needsEmailedCode(role: string): boolean {
  return role === "Owner" || role === "Management" || role === "Accountant";
}
