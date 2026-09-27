import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { DataTable } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/States";

vi.mock("next/link", () => ({
  default: ({ children, href }: { children: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn() }),
}));

interface Row {
  id: number;
  reference: string;
  title: string;
}

const rows: Row[] = [
  { id: 1, reference: "GRV-0001", title: "Dust from haulage trucks" },
  { id: 2, reference: "GRV-0002", title: "Compensation not paid" },
];

describe("data table", () => {
  const props = {
    rows,
    rowKey: (row: Row) => row.id,
    href: (row: Row) => `/grievances/${row.id}`,
    columns: [
      { key: "reference", header: "Case", cell: (row: Row) => row.reference },
      { key: "title", header: "Title", cell: (row: Row) => row.title },
    ],
    mobileCard: (row: Row) => <div>{row.title}</div>,
  };

  it("renders a desktop table and a mobile card list from one definition", () => {
    render(<DataTable {...props} />);

    expect(screen.getByRole("table")).toBeInTheDocument();
    // Each row appears twice: once in the table, once in the phone card list.
    expect(screen.getAllByText("Dust from haulage trucks")).toHaveLength(2);
  });

  it("never nests a link inside a link", () => {
    const { container } = render(<DataTable {...props} />);

    container.querySelectorAll("a").forEach((anchor) => {
      expect(anchor.querySelector("a")).toBeNull();
    });
  });

  it("shows the empty state instead of an empty grid", () => {
    render(
      <DataTable
        {...props}
        rows={[]}
        emptyState={<EmptyState title="No cases yet" description="Log the first one." />}
      />,
    );

    expect(screen.getByText("No cases yet")).toBeInTheDocument();
    expect(screen.queryByRole("table")).not.toBeInTheDocument();
  });

  it("announces which column is sorted", () => {
    render(<DataTable {...props} sort="-reference" onSortChange={vi.fn()} columns={[{ key: "reference", header: "Case", sortable: true, cell: (row: Row) => row.reference }]} />);

    expect(screen.getByRole("columnheader")).toHaveAttribute("aria-sort", "descending");
  });
});
