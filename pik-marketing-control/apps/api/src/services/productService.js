import * as productRepository from '../repositories/productRepository.js';
import { notFound } from '../utils/errors.js';

export function list(filters) {
  return productRepository.list(filters);
}

export function facets() {
  return productRepository.facets();
}

export async function get(id) {
  const product = await productRepository.findById(id);
  if (!product) throw notFound('Produk tidak ditemukan.');
  return product;
}

/** Product with current stock, lead times and open order lines. */
export async function getDetail(id) {
  const product = await get(id);
  const [stock, leadTimes, openOrderLines] = await Promise.all([
    productRepository.currentStock(id),
    productRepository.leadTimes(id),
    productRepository.openOrderLines(id),
  ]);
  return { ...product, stock, lead_times: leadTimes, open_order_lines: openOrderLines };
}

export async function create(values, actor) {
  const id = await productRepository.insert(values, actor.id);
  return get(id);
}

export async function update(id, values, actor) {
  await get(id);
  await productRepository.update(id, values, actor.id);
  return get(id);
}

export async function setActive(id, isActive, actor) {
  await get(id);
  await productRepository.update(id, { is_active: isActive }, actor.id);
  return get(id);
}
