module.exports = {
  content: [
    "./dashboard/**/*.php",
    "./auth/**/*.php",
    "./partials/**/*.php",
    "./public/**/*.php",
    "./assets/design/ui/**/*.php",
    "./assets/design/components/**/*.php",
    "./assets/design/js/**/*.js",
  ],
  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: "#0ea5e9",
          dark: "#0284c7",
          ink: "#0b5f7a",
          soft: "#e0f2fe",
        },
        secondary: {
          DEFAULT: "#0369a1",
          soft: "#dbeafe",
        },
        success: {
          DEFAULT: "#10b981",
          soft: "#dcfce7",
        },
        warning: {
          DEFAULT: "#f59e0b",
          soft: "#fef3c7",
        },
        danger: {
          DEFAULT: "#ef4444",
          soft: "#fee2e2",
        },
        surface: "#ffffff",
        background: "#f4f9fc",
        text: "#0f172a",
        muted: "#64748b",
        border: "#cbd5e1",
      },
      spacing: {
        1.5: "6px",
        2.5: "10px",
        3.5: "14px",
        4.5: "18px",
        5.5: "22px",
        6: "24px",
      },
      borderRadius: {
        sm: "6px",
        DEFAULT: "10px",
        md: "12px",
        lg: "14px",
        xl: "16px",
        pill: "999px",
      },
      fontSize: {
        xs: ["11px", { lineHeight: "1.5" }],
        sm: ["12px", { lineHeight: "1.5" }],
        base: ["14px", { lineHeight: "1.6" }],
        md: ["15px", { lineHeight: "1.6" }],
        lg: ["16px", { lineHeight: "1.5" }],
        xl: ["18px", { lineHeight: "1.4" }],
        "2xl": ["20px", { lineHeight: "1.3" }],
        "3xl": ["26px", { lineHeight: "1.2" }],
      },
      fontFamily: {
        sans: ["Segoe UI", "Tahoma", "Arial", "sans-serif"],
      },
      boxShadow: {
        panel: "0 14px 40px rgba(15, 23, 42, 0.08)",
        soft: "0 8px 24px rgba(15, 23, 42, 0.08)",
        modal: "0 24px 64px rgba(2, 6, 23, 0.2)",
      },
      backgroundImage: {
        "shell-gradient":
          "linear-gradient(180deg, rgba(12, 74, 110, 1) 0%, rgba(7, 89, 133, 1) 44%, rgba(224, 242, 254, 1) 100%)",
        "nav-gradient":
          "linear-gradient(180deg, rgba(8, 47, 73, 1) 0%, rgba(12, 74, 110, 1) 100%)",
      },
    },
  },
  plugins: [],
};
