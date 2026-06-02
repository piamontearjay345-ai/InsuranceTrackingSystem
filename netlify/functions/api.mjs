import { handle } from '../lib/router.mjs';

export async function handler(event, context) {
  context.callbackWaitsForEmptyEventLoop = false;
  return handle(event);
}
