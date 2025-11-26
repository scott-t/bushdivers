import {
  Box,
  Button,
  Card,
  CardBody,
  Flex,
  FormControl,
  FormErrorMessage,
  FormLabel,
  Input,
} from '@chakra-ui/react'
import { router, usePage } from '@inertiajs/react'
import React, { useState } from 'react'

import AdminLayout from '../../components/layout/AdminLayout.jsx'

const ManufacturerEdit = ({ manufacturer }) => {
  const { errors } = usePage().props
  const [values, setValues] = useState({
    name: manufacturer?.name ?? '',
    logo_url: manufacturer?.logo_url ?? '',
  })

  function handleChange(e) {
    const key = e.target.id
    const value = e.target.value
    setValues((values) => ({
      ...values,
      [key]: value,
    }))
  }

  function handleSubmit(e) {
    e.preventDefault()
    router.post(
      manufacturer
        ? `/admin/manufacturers/edit/${manufacturer.id}`
        : '/admin/manufacturers/create',
      values
    )
  }

  return (
    <Card>
      <CardBody>
        <form onSubmit={handleSubmit}>
          <Box>
            <FormControl isInvalid={errors.name}>
              <FormLabel htmlFor="name">Manufacturer Name</FormLabel>
              <Input
                id="name"
                value={values.name}
                onChange={handleChange}
                type="text"
              />
              {errors.name && (
                <FormErrorMessage>{errors.name}</FormErrorMessage>
              )}
            </FormControl>
            <FormControl isInvalid={errors.logo_url}>
              <FormLabel htmlFor="logo_url">Logo URL (optional)</FormLabel>
              <Input
                id="logo_url"
                value={values.logo_url}
                onChange={handleChange}
                type="text"
              />
              {errors.logo_url && (
                <FormErrorMessage>{errors.logo_url}</FormErrorMessage>
              )}
            </FormControl>
          </Box>
          <Flex justifyContent="right" mt={4}>
            <Button type="submit">Save Manufacturer</Button>
          </Flex>
        </form>
      </CardBody>
    </Card>
  )
}

ManufacturerEdit.layout = (page) => (
  <AdminLayout
    children={page}
    heading="Fleet Management"
    subHeading={
      page.props.manufacturer ? 'Edit Manufacturer' : 'Add Manufacturer'
    }
  />
)

export default ManufacturerEdit
