export const DEFAULT_PROJECT_SERVICE_DURATION = 60;
export const DEFAULT_ADDON_SERVICE_DURATION = 30;

export function resolveProjectDuration(value) {
  const num = Number(value);
  return num > 0 ? num : DEFAULT_PROJECT_SERVICE_DURATION;
}

export function resolveAddonDuration(value) {
  const num = Number(value);
  return num > 0 ? num : DEFAULT_ADDON_SERVICE_DURATION;
}
