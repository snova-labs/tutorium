/** A failed API call, with Laravel's validation errors kept field by field. */
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly errors: Record<string, string[]> = {},
    public readonly body: unknown = null,
  ) {
    super(message);
    this.name = "ApiError";
  }

  /** The first message for a field, for showing beside that field. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0];
  }

  /** Every message, flattened, for a form-level summary. */
  allMessages(): string[] {
    const messages = Object.values(this.errors).flat();

    return messages.length > 0 ? messages : [this.message];
  }
}

export function isApiError(value: unknown): value is ApiError {
  return value instanceof ApiError;
}
