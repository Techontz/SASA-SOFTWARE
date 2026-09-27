import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { ChoiceCard, Field, Input, Textarea } from "@/components/ui/Form";

/**
 * The confidentiality choice is the most consequential control in SASA: it
 * decides what may be stored at all. It must read as three plainly different
 * options, not as a dropdown somebody skims past.
 */
describe("form primitives", () => {
  it("shows a validation message as an alert, next to its field", async () => {
    render(
      <Field label="The account" required error="Write down what the complainant told you." htmlFor="description">
        <Textarea id="description" invalid />
      </Field>,
    );

    expect(screen.getByRole("alert")).toHaveTextContent("Write down what the complainant told you.");
    expect(screen.getByLabelText(/The account/)).toHaveAttribute("aria-invalid", "true");
  });

  it("marks optional fields as optional rather than leaving people guessing", () => {
    render(
      <Field label="Phone" optional htmlFor="phone">
        <Input id="phone" />
      </Field>,
    );

    expect(screen.getByText("optional")).toBeInTheDocument();
  });

  it("offers confidentiality as three explained choices", async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();

    render(
      <>
        <ChoiceCard
          checked={false}
          onSelect={onSelect}
          title="Confidential"
          description="Released only to the handling group."
        />
        <ChoiceCard
          checked
          onSelect={() => {}}
          title="Anonymous"
          description="No identity is recorded at all."
          tone="danger"
        />
      </>,
    );

    expect(screen.getByText("Released only to the handling group.")).toBeInTheDocument();
    expect(screen.getByText("No identity is recorded at all.")).toBeInTheDocument();

    await user.click(screen.getByText("Confidential"));
    await waitFor(() => expect(onSelect).toHaveBeenCalled());
  });
});
